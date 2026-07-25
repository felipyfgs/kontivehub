<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SyncCursorStatus;
use App\Enums\TaxRegimeCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\BulkUpdateClientStatusRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientCustomField;
use App\Models\Establishment;
use App\Models\SyncCursor;
use App\Services\Audit\AuditLogger;
use App\Services\Clients\CaptureEligibilityService;
use App\Services\Clients\ClientRootConflictException;
use App\Services\Clients\CreateClientWithEstablishment;
use App\Services\Clients\RefreshClientRegistration;
use App\Services\Integra\ClientProcuracaoValidityResolver;
use App\Support\CurrentOffice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ClientController extends Controller
{
    public function index(Request $request, ClientProcuracaoValidityResolver $procuracoes): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        // Lista canônica: apenas Cliente-raiz (matrix_client_id null). Filiais legadas
        // colapsadas/soft-deleted não entram; estabelecimentos cobrem as filiais.
        $base = Client::query()->whereNull('matrix_client_id');

        if ($search = $request->string('q')->toString()) {
            $needle = '%'.mb_strtolower($search).'%';
            $cnpjNeedle = '%'.strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $search) ?? $search).'%';
            $base->where(function ($q) use ($needle, $cnpjNeedle): void {
                $q->whereRaw('LOWER(legal_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$needle])
                    ->orWhere('root_cnpj', 'like', $cnpjNeedle)
                    ->orWhereHas('establishments', function ($est) use ($cnpjNeedle): void {
                        $est->where('cnpj', 'like', $cnpjNeedle);
                    });
            });
        }

        // KPIs do escritório (escopo da busca textual, sem filtro de estado da tabela)
        $statsQuery = (clone $base);
        $total = (clone $statsQuery)->count();
        $active = (clone $statsQuery)->where('is_active', true)->count();
        // credentials() (hasMany, qualquer status): KPIs que filtram além de ACTIVE.
        // credential() (hasOne ACTIVE) é só para resumo operacional na lista.
        $withActiveCredential = (clone $statsQuery)
            ->whereHas('credential')
            ->count();
        $withoutCredential = (clone $statsQuery)
            ->whereDoesntHave('credentials', function ($q): void {
                $q->whereIn('status', ['ACTIVE', 'PENDING']);
            })
            ->count();
        $credentialExpiring = (clone $statsQuery)
            ->whereHas('credentials', function ($q): void {
                $q->where('status', 'ACTIVE')
                    ->where(function ($inner): void {
                        $inner->where('expires_alert_30', true)
                            ->orWhere('expires_alert_7', true)
                            ->orWhere('expires_alert_1', true)
                            ->orWhereBetween('valid_to', [now(), now()->addDays(30)]);
                    });
            })
            ->count();
        $credentialExpired = (clone $statsQuery)
            ->whereHas('credentials', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('status', 'EXPIRED')
                        ->orWhere(function ($expired): void {
                            $expired->where('status', 'ACTIVE')->where('valid_to', '<', now());
                        });
                });
            })
            ->count();
        // Captura problemática: cursor BLOCKED/ERROR em qualquer estabelecimento do cliente.
        $captureProblem = (clone $statsQuery)
            ->whereHas('establishments.syncCursors', function ($q): void {
                $q->whereIn('status', [
                    SyncCursorStatus::Blocked->value,
                    SyncCursorStatus::Error->value,
                ]);
            })
            ->count();

        $dashboardStats = [];
        if ($request->boolean('dashboard')) {
            $dashboardStats = [
                'credential_ok' => (clone $statsQuery)
                    ->whereHas('credentials', function ($q): void {
                        $q->where('status', 'ACTIVE')
                            ->where('valid_to', '>', now()->addDays(30))
                            ->where('expires_alert_30', false)
                            ->where('expires_alert_7', false)
                            ->where('expires_alert_1', false);
                    })
                    ->count(),
                'client_growth_12m' => $this->clientGrowthLastTwelveMonths($statsQuery),
            ];
        }

        // Filtro de estado só na lista (USelect do template)
        if ($request->filled('is_active')) {
            $base->where('is_active', $request->boolean('is_active'));
        }

        match ($request->string('operational_filter')->toString()) {
            'with_credential' => $base->whereHas('credential'),
            'without_credential' => $base->whereDoesntHave('credential'),
            'expiring' => $base->whereHas('credentials', function ($q): void {
                $q->where('status', 'ACTIVE')
                    ->where(function ($inner): void {
                        $inner->where('expires_alert_30', true)
                            ->orWhere('expires_alert_7', true)
                            ->orWhere('expires_alert_1', true)
                            ->orWhereBetween('valid_to', [now(), now()->addDays(30)]);
                    });
            }),
            'credential_expired' => $base->whereHas('credentials', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('status', 'EXPIRED')
                        ->orWhere(function ($expired): void {
                            $expired->where('status', 'ACTIVE')->where('valid_to', '<', now());
                        });
                });
            }),
            'capture_problem' => $base->whereHas('establishments.syncCursors', function ($q): void {
                $q->whereIn('status', [
                    SyncCursorStatus::Blocked->value,
                    SyncCursorStatus::Error->value,
                ]);
            }),
            default => null,
        };

        $categoryIds = $this->positiveIntegerCsv($request->query('category_ids'), 25);
        if ($categoryIds !== []) {
            // OR: qualquer categoria selecionada inclui o cliente.
            $base->whereHas('categories', fn (Builder $query) => $query->whereIn(
                'client_categories.id',
                $categoryIds,
            ));
        }

        $taxRegimes = $this->taxRegimeCsv($request->query('tax_regimes'));
        if ($taxRegimes !== []) {
            $includeNotInformed = in_array('NOT_INFORMED', $taxRegimes, true);
            $canonical = array_values(array_diff($taxRegimes, ['NOT_INFORMED']));
            $storageValues = $this->taxRegimeStorageValues($canonical);
            $base->where(function (Builder $query) use ($storageValues, $includeNotInformed): void {
                if ($storageValues !== []) {
                    $query->whereIn('tax_regime', $storageValues);
                }
                if ($includeNotInformed) {
                    $storageValues !== []
                        ? $query->orWhereNull('tax_regime')
                        : $query->whereNull('tax_regime');
                }
            });
        }

        $procuracaoStatuses = $this->procuracaoStatusCsv($request->query('procuracao_statuses'));
        if ($procuracaoStatuses !== []) {
            $this->applyProcuracaoStatusesFilter($base, $procuracaoStatuses);
        }

        $sort = match ($request->string('sort')->toString()) {
            'cnpj' => 'root_cnpj',
            'is_active' => 'is_active',
            'created_at' => 'created_at',
            'tax_regime' => 'tax_regime',
            default => 'legal_name',
        };
        // LFU-07: aceita `sort_direction` ou `direction`.
        $directionRaw = $request->query('sort_direction', $request->query('direction', 'asc'));
        $direction = is_string($directionRaw) && strtolower($directionRaw) === 'desc' ? 'desc' : 'asc';

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginator = (clone $base)
            ->withCount('establishments')
            ->with([
                'credential',
                'procuracaoSync',
                'procuracaoSnapshots' => fn ($q) => $q->where(
                    'environment',
                    (string) config('serpro.default_environment', 'TRIAL'),
                ),
                'categories' => fn ($q) => $q->orderBy('name')->orderBy('id'),
                // Estabelecimentos + cursores para resumo de captura/sync sem N+1.
                'establishments' => fn ($q) => $q->orderBy('id')->with('syncCursors'),
            ])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($perPage);

        $items = collect($paginator->items())->map(function (Client $client) use ($procuracoes) {
            $payload = $this->serializeClient($client, $procuracoes);
            $primary = $client->establishments->first();
            $payload['cnpj'] = $primary?->cnpj;
            $payload['trade_name'] = $primary?->trade_name;
            $credential = $client->credential;
            $payload['credential_summary'] = $credential === null
                ? null
                : [
                    'status' => $credential->status?->value ?? $credential->status,
                    'valid_to' => $credential->valid_to?->toIso8601String(),
                    'expires_alert_30' => (bool) $credential->expires_alert_30,
                    'expires_alert_7' => (bool) $credential->expires_alert_7,
                    'expires_alert_1' => (bool) $credential->expires_alert_1,
                ];
            $payload['capture_summary'] = $this->buildCaptureSummary($client->establishments);
            $payload['sync_summary'] = $this->buildSyncSummary($client->establishments);

            return $payload;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'stats' => [
                    'total' => $total,
                    'active' => $active,
                    'with_credential' => $withActiveCredential,
                    'without_credential' => $withoutCredential,
                    'credential_expiring_30d' => $credentialExpiring,
                    'credential_expired' => $credentialExpired,
                    'capture_problem' => $captureProblem,
                    ...$dashboardStats,
                ],
            ],
        ]);
    }

    /**
     * @param  Builder<Client>  $query
     * @return list<array{month: string, total: int}>
     */
    private function clientGrowthLastTwelveMonths(Builder $query): array
    {
        $start = now()->startOfMonth()->subMonths(11);
        $driver = $query->getConnection()->getDriverName();
        $monthExpression = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "to_char(created_at, 'YYYY-MM')";

        $counts = (clone $query)
            ->where('created_at', '>=', $start)
            ->selectRaw("{$monthExpression} as month_key, COUNT(*) as aggregate")
            ->groupByRaw($monthExpression)
            ->pluck('aggregate', 'month_key');

        $cumulative = (clone $query)->where('created_at', '<', $start)->count();
        $series = [];
        for ($offset = 0; $offset < 12; $offset++) {
            $month = $start->copy()->addMonths($offset)->format('Y-m');
            $cumulative += (int) ($counts[$month] ?? 0);
            $series[] = ['month' => $month, 'total' => $cumulative];
        }

        return $series;
    }

    public function store(
        StoreClientRequest $request,
        CurrentOffice $currentOffice,
        CreateClientWithEstablishment $creator,
    ): JsonResponse {
        $this->authorize('create', Client::class);

        $officeId = $currentOffice->office()->id;

        try {
            $result = $creator->handle($officeId, $request->validated());
        } catch (ClientRootConflictException $e) {
            $payload = [
                'message' => $e->getMessage(),
                'errors' => ['cnpj' => ['CNPJ já cadastrado neste escritório.']],
            ];
            if ($e->existingClient !== null) {
                $payload['data'] = [
                    'existing_client_id' => $e->existingClient->id,
                    'existing_client' => [
                        'id' => $e->existingClient->id,
                        'legal_name' => $e->existingClient->legal_name,
                        'root_cnpj' => $e->existingClient->root_cnpj,
                    ],
                ];
            }

            return response()->json($payload, 409);
        } catch (UniqueConstraintViolationException $e) {
            // Corrida / unique físico (office_id, cnpj) — 409 genérico do escritório.
            return response()->json([
                'message' => 'CNPJ já cadastrado neste escritório.',
                'errors' => ['cnpj' => ['CNPJ já cadastrado neste escritório.']],
            ], 409);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return response()->json([
                    'message' => 'CNPJ já cadastrado neste escritório.',
                    'errors' => ['cnpj' => ['CNPJ já cadastrado neste escritório.']],
                ], 409);
            }

            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['cnpj' => [$e->getMessage()]],
            ], 422);
        } catch (\RuntimeException $e) {
            // Limite comercial de clientes (franquia do plano / negociado).
            if (str_contains($e->getMessage(), 'Limite de clientes')
                || str_contains($e->getMessage(), 'sem assinatura')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['max_clients' => [$e->getMessage()]],
                    'code' => 'MAX_CLIENTS_REACHED',
                ], 422);
            }

            throw $e;
        }

        return response()->json([
            'data' => [
                'client' => $this->serializeClient($result['client']),
                'establishment' => $this->serializeEstablishment($result['establishment']),
                'contact' => $result['contact'] !== null
                    ? $this->serializeContact($result['contact'])
                    : null,
                'custom_fields' => collect($result['custom_fields'])
                    ->map(fn ($field) => $field->toPublicArray())
                    ->values(),
            ],
        ], 201);
    }

    public function show(
        Client $client,
        CaptureEligibilityService $eligibility,
        ClientProcuracaoValidityResolver $procuracoes,
    ): JsonResponse {
        $this->authorize('view', $client);

        $client->load([
            'credential',
            'procuracaoSync',
            'procuracaoSnapshots' => fn ($q) => $q->where(
                'environment',
                (string) config('serpro.default_environment', 'TRIAL'),
            ),
            'establishments' => fn ($q) => $q->orderBy('cnpj'),
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name'),
            'customFields' => fn ($q) => $q->orderBy('id'),
            'categories' => fn ($q) => $q->orderBy('name')->orderBy('id'),
            'workDepartment',
            'matrix.establishments' => fn ($q) => $q->orderBy('id')->limit(1),
            'branches' => fn ($q) => $q->orderBy('legal_name')
                ->with(['establishments' => fn ($eq) => $eq->orderBy('id')->limit(1), 'credential']),
        ]);

        $establishments = $client->establishments->map(function ($est) use ($eligibility) {
            $payload = $this->serializeEstablishment($est);
            $eval = $eligibility->evaluate($est);
            $payload['capture_eligibility'] = $eval;

            return $payload;
        });

        $data = $this->serializeClient($client, $procuracoes);
        $primary = $client->establishments->firstWhere('is_matrix', true)
            ?? $client->establishments->first();
        $data['cnpj'] = $primary?->cnpj;
        $data['trade_name'] = $primary?->trade_name;
        $data['establishments'] = $establishments;
        $data['canonical_aggregate'] = true;
        // matrix/branches: compat UI legada. Autoridade de filiais = establishments.
        $data['matrix'] = $client->matrix !== null
            ? $this->serializeLinkedClient($client->matrix)
            : null;
        $data['branches'] = $client->branches
            ->map(fn (Client $branch) => $this->serializeLinkedClient($branch))
            ->values();
        $data['contacts'] = $client->contacts->map(fn ($c) => $this->serializeContact($c))->values();
        $data['custom_fields'] = $client->customFields->map(fn ($field) => $field->toPublicArray())->values();
        // Resumo seguro do A1 (status/validade/alertas) — sem vault/PFX — para OPERATOR/VIEWER.
        // Espelha o payload da listagem; detalhe completo continua em GET /credential (ADMIN+2FA).
        $credential = $client->credential;
        $data['credential_summary'] = $credential === null
            ? null
            : [
                'status' => $credential->status?->value ?? $credential->status,
                'valid_to' => $credential->valid_to?->toIso8601String(),
                'expires_alert_30' => (bool) $credential->expires_alert_30,
                'expires_alert_7' => (bool) $credential->expires_alert_7,
                'expires_alert_1' => (bool) $credential->expires_alert_1,
            ];

        return response()->json(['data' => $data]);
    }

    public function update(UpdateClientRequest $request, Client $client, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validated();
        $client->fill($data);
        $changed = array_keys($client->getDirty());
        $client->save();

        $audit->record('client.update', 'SUCCESS', $client, [
            'fields' => $changed,
        ]);

        $fresh = $client->fresh()?->load([
            'categories' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            'workDepartment',
        ]) ?? $client;

        return response()->json(['data' => $this->serializeClient($fresh)]);
    }

    public function updateCustomField(
        Request $request,
        Client $client,
        ClientCustomField $customField,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $client);

        if ((int) $customField->client_id !== (int) $client->id) {
            abort(404);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'value' => ['nullable', 'string', 'max:10000'],
        ]);

        if (array_key_exists('label', $data)) {
            $customField->label = $data['label'];
        }
        if (array_key_exists('is_active', $data)) {
            $customField->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('value', $data) && $customField->type === 'TEXT') {
            $customField->value_text = $data['value'];
        }
        $customField->save();

        $audit->record('client.custom_field.update', 'SUCCESS', $client, [
            'custom_field_id' => $customField->id,
            'fields' => array_keys($data),
        ]);

        return response()->json(['data' => $customField->toPublicArray()]);
    }

    public function bulkStatus(BulkUpdateClientStatusRequest $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validated();
        $clientIds = array_values(array_map('intval', $data['client_ids']));
        $isActive = (bool) $data['is_active'];
        $inactiveReason = $isActive
            ? null
            : trim((string) $data['inactive_reason']);

        /** @var Collection<int, Client> $clients */
        $clients = DB::transaction(function () use ($clientIds, $isActive, $inactiveReason): Collection {
            $clients = Client::query()
                ->whereNull('matrix_client_id')
                ->whereKey($clientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($clients->count() !== count($clientIds)) {
                throw ValidationException::withMessages([
                    'client_ids' => ['Um ou mais clientes não pertencem ao escritório atual ou não estão disponíveis.'],
                ]);
            }

            foreach ($clientIds as $clientId) {
                /** @var Client $client */
                $client = $clients->get($clientId);
                $this->authorize('update', $client);
                $client->forceFill([
                    'is_active' => $isActive,
                    'inactive_reason' => $inactiveReason,
                ])->save();
            }

            return $clients->values();
        });

        foreach ($clients as $client) {
            $audit->record('client.bulk_status_update', 'SUCCESS', $client, [
                'is_active' => $isActive,
                'batch_size' => count($clientIds),
            ]);
        }

        return response()->json([
            'data' => [
                'updated' => $clients->count(),
                'client_ids' => $clientIds,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * Detecta violação de unique (PostgreSQL 23505 / SQLite) quando a exceção tipada não for lançada.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeClient(
        Client $client,
        ?ClientProcuracaoValidityResolver $procuracoes = null,
    ): array {
        $taxRegime = TaxRegimeCode::fromInput(
            is_string($client->tax_regime) ? $client->tax_regime : null
        );
        $payload = [
            'id' => $client->id,
            'office_id' => $client->office_id,
            'legal_name' => $client->legal_name,
            'display_name' => $client->display_name,
            'name' => $client->displayLabel(), // compat UI legada
            'root_cnpj' => $client->root_cnpj,
            'matrix_client_id' => $client->matrix_client_id,
            'legal_nature_code' => $client->legal_nature_code,
            'legal_nature_name' => $client->legal_nature_name,
            'company_size_code' => $client->company_size_code,
            'company_size_name' => $client->company_size_name,
            'capital_social' => $client->capital_social !== null ? (string) $client->capital_social : null,
            'responsible_qualification_code' => $client->responsible_qualification_code,
            'responsible_qualification_name' => $client->responsible_qualification_name,
            'tax_regime' => $taxRegime?->value,
            'tax_regime_label' => $taxRegime?->label(),
            'work_department_id' => $client->work_department_id,
            'work_department' => $client->relationLoaded('workDepartment') && $client->workDepartment
                ? [
                    'id' => (int) $client->workDepartment->id,
                    'name' => (string) $client->workDepartment->name,
                    'code' => (string) $client->workDepartment->code,
                ]
                : null,
            'categories' => $client->relationLoaded('categories')

                ? $client->categories->map(static fn ($category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'color' => (string) $category->color,
                    'is_active' => (bool) $category->is_active,
                ])->values()->all()
                : [],
            'notes' => $client->notes,
            'is_active' => $client->is_active,
            'inactive_reason' => $client->inactive_reason,
            'registration_source' => $client->registration_source?->value ?? $client->registration_source,
            'registration_refreshed_at' => $client->registration_refreshed_at?->toIso8601String(),
            'establishments_count' => $client->establishments_count ?? null,
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];

        if ($procuracoes !== null) {
            $projection = $procuracoes->resolve(
                $client->relationLoaded('procuracaoSync') ? $client->procuracaoSync : null,
                $client->relationLoaded('procuracaoSnapshots') ? $client->procuracaoSnapshots->first() : null,
            );
            $payload['procuracao_status'] = $projection['status'];
            $payload['procuracao_valid_to'] = $projection['valid_to'];
            $payload['procuracao_checked_at'] = $projection['checked_at'];
        }

        return $payload;
    }

    /** @return list<int> */
    private function positiveIntegerCsv(mixed $raw, int $limit): array
    {
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
        $ids = [];
        foreach ($parts as $part) {
            $text = trim((string) $part);
            if ($text === '' || ! ctype_digit($text) || (int) $text < 1) {
                continue;
            }
            $ids[(int) $text] = (int) $text;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function procuracaoStatusCsv(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
        $allowed = ['authorized', 'expiring', 'expired', 'missing', 'unverified', 'verifying', 'failed'];
        $values = [];
        foreach ($parts as $part) {
            $value = strtolower(trim((string) $part));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $values[$value] = $value;
            }
            if (count($values) >= 10) {
                break;
            }
        }

        return array_values($values);
    }

    /**
     * Filtra pela projeção operacional de procuração (sync oficial; snapshot só como fallback).
     *
     * @param  Builder<Client>  $base
     * @param  list<string>  $statuses
     */
    private function applyProcuracaoStatusesFilter(Builder $base, array $statuses): void
    {
        $environment = (string) config('serpro.default_environment', 'TRIAL');
        $now = now();
        $horizon = now()->addDays(30);

        $base->where(function (Builder $outer) use ($statuses, $environment, $now, $horizon): void {
            foreach ($statuses as $status) {
                $outer->orWhere(function (Builder $branch) use ($status, $environment, $now, $horizon): void {
                    match ($status) {
                        'authorized' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source
                                ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                ->where(function (Builder $valid) use ($horizon): void {
                                    $valid->whereNull('valid_to')
                                        ->orWhere('valid_to', '>', $horizon);
                                }),
                        ),
                        'expiring' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source
                                ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                ->where('valid_to', '>', $now)
                                ->where('valid_to', '<=', $horizon),
                        ),
                        'expired' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(function (Builder $expired) use ($now): void {
                                $expired->where('status', ClientProcuracaoSyncStatus::Expired->value)
                                    ->orWhere(function (Builder $authorizedExpired) use ($now): void {
                                        $authorizedExpired
                                            ->where('status', ClientProcuracaoSyncStatus::Authorized->value)
                                            ->whereNotNull('valid_to')
                                            ->where('valid_to', '<=', $now);
                                    });
                            }),
                        ),
                        'missing' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Missing->value,
                            ),
                        ),
                        'unverified' => $branch->where(function (Builder $unverified) use ($environment): void {
                            $unverified
                                ->where(function (Builder $absent) use ($environment): void {
                                    $absent->whereDoesntHave('procuracaoSync')
                                        ->whereDoesntHave('procuracaoSnapshots', function (Builder $snap) use ($environment): void {
                                            $snap->where('environment', $environment);
                                        });
                                })
                                ->orWhere(function (Builder $explicit) use ($environment): void {
                                    $this->whereProjectedProcuracao(
                                        $explicit,
                                        $environment,
                                        fn (Builder $source) => $source->where(
                                            'status',
                                            ClientProcuracaoSyncStatus::Unverified->value,
                                        ),
                                    );
                                });
                        }),
                        'verifying' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Verifying->value,
                            ),
                        ),
                        'failed' => $this->whereProjectedProcuracao(
                            $branch,
                            $environment,
                            fn (Builder $source) => $source->where(
                                'status',
                                ClientProcuracaoSyncStatus::Failed->value,
                            ),
                        ),
                        default => null,
                    };
                });
            }
        });
    }

    /**
     * Preferência: procuracaoSync; sem sync, usa snapshot do environment default.
     *
     * @param  Builder<Client>  $branch
     * @param  callable(Builder): void  $constrain
     */
    private function whereProjectedProcuracao(Builder $branch, string $environment, callable $constrain): void
    {
        $branch->where(function (Builder $outer) use ($environment, $constrain): void {
            $outer->whereHas('procuracaoSync', function (Builder $sync) use ($constrain): void {
                $constrain($sync);
            })->orWhere(function (Builder $fallback) use ($environment, $constrain): void {
                $fallback->whereDoesntHave('procuracaoSync')
                    ->whereHas('procuracaoSnapshots', function (Builder $snap) use ($environment, $constrain): void {
                        $snap->where('environment', $environment);
                        $constrain($snap);
                    });
            });
        });
    }

    /** @return list<string> */
    private function taxRegimeCsv(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
        $allowed = [...TaxRegimeCode::currentProjectionValues(), 'NOT_INFORMED'];
        $values = [];
        foreach ($parts as $part) {
            $value = strtoupper(trim((string) $part));
            if ($value !== '' && in_array($value, $allowed, true)) {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * Expande códigos canônicos para formas legadas ainda possíveis em clients.tax_regime.
     *
     * @param  list<string>  $canonical
     * @return list<string>
     */
    private function taxRegimeStorageValues(array $canonical): array
    {
        $values = [];
        foreach ($canonical as $code) {
            $regime = TaxRegimeCode::tryFrom($code);
            if ($regime === null) {
                $values[$code] = $code;

                continue;
            }

            foreach ($regime->storageFilterValues() as $value) {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * Resumo de cliente vinculado (matriz ou filial) para a aba Estabelecimentos.
     *
     * @return array<string, mixed>
     */
    private function serializeLinkedClient(Client $client): array
    {
        $primary = $client->relationLoaded('establishments')
            ? $client->establishments->first()
            : $client->establishments()->orderBy('id')->first();

        $credential = $client->relationLoaded('credential')
            ? $client->credential
            : null;

        return [
            'id' => $client->id,
            'legal_name' => $client->legal_name,
            'display_name' => $client->display_name,
            'name' => $client->displayLabel(),
            'root_cnpj' => $client->root_cnpj,
            'matrix_client_id' => $client->matrix_client_id,
            'cnpj' => $primary?->cnpj,
            'trade_name' => $primary?->trade_name,
            'is_matrix' => (bool) ($primary?->is_matrix ?? $client->matrix_client_id === null),
            'is_active' => $client->is_active,
            'credential_summary' => $credential === null
                ? null
                : [
                    'status' => $credential->status?->value ?? $credential->status,
                    'valid_to' => $credential->valid_to?->toIso8601String(),
                ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEstablishment(Establishment $est): array
    {
        return [
            'id' => $est->id,
            'office_id' => $est->office_id,
            'client_id' => $est->client_id,
            'cnpj' => $est->cnpj,
            'trade_name' => $est->trade_name,
            'is_matrix' => $est->is_matrix,
            'is_active' => $est->is_active,
            'registration_status' => $est->registration_status?->value ?? $est->registration_status,
            'registration_status_at' => $est->registration_status_at?->toDateString(),
            'registration_status_reason' => $est->registration_status_reason,
            'activity_started_at' => $est->activity_started_at?->toDateString(),
            'main_cnae_code' => $est->main_cnae_code,
            'main_cnae_name' => $est->main_cnae_name,
            'secondary_cnaes' => is_array($est->secondary_cnaes) ? $est->secondary_cnaes : [],
            'state_registrations' => is_array($est->state_registrations) ? $est->state_registrations : [],
            'shareholders' => is_array($est->shareholders) ? $est->shareholders : [],
            'address' => $est->addressPayload(),
            'public_email' => $est->public_email,
            'public_phone' => $est->public_phone,
            'public_phone_secondary' => $est->public_phone_secondary,
            'public_fax' => $est->public_fax,
            'special_situation' => $est->special_situation,
            'special_situation_at' => $est->special_situation_at?->toDateString(),
            'simples_optant' => $est->simples_optant,
            'mei_optant' => $est->mei_optant,
            'capture_enabled' => $est->capture_enabled,
            'registration_source' => $est->registration_source?->value ?? $est->registration_source,
            'registration_refreshed_at' => $est->registration_refreshed_at?->toIso8601String(),
            'created_at' => $est->created_at?->toIso8601String(),
            'updated_at' => $est->updated_at?->toIso8601String(),
        ];
    }

    public function refreshRegistration(
        Request $request,
        Client $client,
        RefreshClientRegistration $refresher,
    ): JsonResponse {
        $this->authorize('update', $client);

        $lookupPayload = $request->input('lookup');
        if ($lookupPayload !== null && ! is_array($lookupPayload)) {
            throw ValidationException::withMessages([
                'lookup' => ['Informe o snapshot de consulta no formato esperado.'],
            ]);
        }

        /** @var array<string, mixed>|null $lookupPayload */
        $result = $refresher->handle($client, is_array($lookupPayload) ? $lookupPayload : null);
        $fresh = $result['client']->load([
            'establishments' => fn ($q) => $q->orderByDesc('is_matrix')->orderBy('id'),
            'contacts',
            'categories',
        ]);

        $payload = $this->serializeClient($fresh);
        $payload['establishments'] = $fresh->establishments
            ->map(fn (Establishment $est): array => $this->serializeEstablishment($est))
            ->values()
            ->all();
        $payload['lookup'] = $result['lookup'];

        return response()->json(['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeContact(ClientContact $contact): array
    {
        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'name' => $contact->name,
            'role' => $contact->role,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_whatsapp' => $contact->is_whatsapp,
            'is_primary' => $contact->is_primary,
            'receives_alerts' => $contact->receives_alerts,
            'notes' => $contact->notes,
            'is_active' => $contact->is_active,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Resumo de captura na lista (sem elegibilidade completa — evita N+1 de policy).
     *
     * @param  Collection<int, Establishment>  $establishments
     * @return array{enabled: bool, status: string, establishments_total: int, establishments_enabled: int}
     */
    private function buildCaptureSummary(Collection $establishments): array
    {
        $total = $establishments->count();
        $enabled = $establishments->filter(fn (Establishment $e) => $e->is_active && $e->capture_enabled)->count();

        $status = match (true) {
            $total === 0 => 'NONE',
            $enabled === 0 => 'OFF',
            $enabled === $total => 'ON',
            default => 'PARTIAL',
        };

        return [
            'enabled' => $enabled > 0,
            'status' => $status,
            'establishments_total' => $total,
            'establishments_enabled' => $enabled,
        ];
    }

    /**
     * Pior status de cursor entre estabelecimentos + último sucesso.
     * Prioridade: BLOCKED > ERROR > RUNNING > WAITING > IDLE > NONE.
     *
     * @param  Collection<int, Establishment>  $establishments
     * @return array{status: string, last_success_at: ?string, has_cursor: bool}
     */
    private function buildSyncSummary(Collection $establishments): array
    {
        /** @var Collection<int, SyncCursor> $cursors */
        $cursors = $establishments->flatMap(fn (Establishment $e) => $e->relationLoaded('syncCursors')
            ? $e->syncCursors
            : collect());

        if ($cursors->isEmpty()) {
            return [
                'status' => 'NONE',
                'last_success_at' => null,
                'has_cursor' => false,
            ];
        }

        $rank = [
            SyncCursorStatus::Blocked->value => 50,
            SyncCursorStatus::Error->value => 40,
            SyncCursorStatus::Running->value => 30,
            SyncCursorStatus::Waiting->value => 20,
            SyncCursorStatus::Idle->value => 10,
        ];

        $worst = 'IDLE';
        $worstRank = -1;
        $lastSuccess = null;

        foreach ($cursors as $cursor) {
            $status = $cursor->status instanceof SyncCursorStatus
                ? $cursor->status->value
                : (string) $cursor->status;
            $r = $rank[$status] ?? 0;
            if ($r > $worstRank) {
                $worstRank = $r;
                $worst = $status;
            }
            $at = $cursor->last_success_at;
            if ($at !== null && ($lastSuccess === null || $at->gt($lastSuccess))) {
                $lastSuccess = $at;
            }
        }

        return [
            'status' => $worst,
            'last_success_at' => $lastSuccess?->toIso8601String(),
            'has_cursor' => true,
        ];
    }
}
