<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\PgdasdDeclarationState;
use App\Enums\PgdasdOperationKind;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Models\PgdasdRbt12Projection;
use App\Models\TaxGuide;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use RuntimeException;
use Throwable;

/**
 * Consultas tenant-scoped da carteira/histórico PGDAS-D (somente leitura local).
 */
final class PgdasdMonitoringQueryService
{
    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
        private readonly PgdasdOperationProjector $projector,
        private readonly PgdasdCommunicationService $communication,
        private readonly PgdasdDasPaymentStateResolver $dasPaymentResolver,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    /**
     * Detalhe de carteira por cliente (para ModulePortfolio detail).
     *
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function portfolioDetails(Tenant $tenant, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $tz = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $periodKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $tz));

        $definition = TaxObligationDefinition::query()->where('code', 'PGDAS_D')->first();
        $projections = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('period_key', $periodKey)
            ->where('obligation_definition_id', $definition?->id ?? 0)
            ->get()
            ->keyBy('client_id');

        $communications = $this->communication->summariesForClients($tenant, $clientIds);

        $lastOps = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('kind', 'DECLARATION')
            ->orderByDesc('transmitted_at')
            ->orderByDesc('declaration_number')
            ->get()
            ->groupBy('client_id');

        $dasByClient = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->where('period_key', $periodKey)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_id');

        $openPaymentCompetencies = $this->openPaymentCompetencies($tenant, $clientIds);

        $rbt12 = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->with(['projection' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_id');

        // A carteira é alimentada por metadados já guardados. Não dispara
        // consulta, job ou qualquer chamada ao Integra Contador.
        $documents = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('fiscal_evidence_artifact_id')
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_id');

        $map = [];
        foreach ($clientIds as $cid) {
            $proj = $projections->get($cid);
            $clientOps = $lastOps->get($cid, collect());
            $lastDecl = $clientOps->first(
                static fn (PgdasdOperation $op) => $op->period_key === $periodKey
            ) ?? $clientOps->first();
            $displayPeriodKey = $lastDecl?->period_key ?: $periodKey;
            $clientRbt = $rbt12->get($cid, collect());
            $latestRbt = $clientRbt->first(
                static fn (PgdasdRbt12Projection $item): bool => ($item->status?->value ?? '') === 'PARSED'
                    && $item->projection?->period_key === $displayPeriodKey
            );
            if ($latestRbt === null) {
                $pointerId = $proj?->pgdasd_latest_rbt12_projection_id;
                if ($pointerId) {
                    $pointed = $clientRbt->first(
                        static fn (PgdasdRbt12Projection $item): bool => (int) $item->id === (int) $pointerId
                    );
                    if ($pointed !== null && ($pointed->status?->value ?? '') === 'PARSED') {
                        $latestRbt = $pointed;
                    }
                }
            }
            if ($latestRbt === null) {
                $latestRbt = $clientRbt->first(
                    static fn (PgdasdRbt12Projection $item): bool => ($item->status?->value ?? '') === 'PARSED'
                );
            }
            if ($latestRbt === null) {
                $latestRbt = $clientRbt->first(
                    static fn (PgdasdRbt12Projection $item): bool => $item->projection?->period_key === $displayPeriodKey
                );
            }

            $state = $proj?->pgdasd_declaration_state?->value
                ?? PgdasdDeclarationState::Unverified->value;
            $lastPublic = $lastDecl?->toPublicArray();
            $rbtPublic = $latestRbt?->toPublicArray();
            $comm = $communications[$cid];
            $hasProductiveConsult = $proj?->pgdasd_last_productive_consulted_at !== null
                || $proj?->last_valid_query_at !== null;
            $paymentPack = $this->dasPaymentResolver->resolve(
                $dasByClient->get($cid, collect()),
                $hasProductiveConsult,
            );

            $map[$cid] = [
                'module_key' => 'simples_mei',
                'submodule' => 'PGDASD',
                'expected_period_key' => $periodKey,
                'declaration_state' => $state,
                'declaration_state_reason' => $this->stateReason($proj),
                'payment_state' => $paymentPack['state']->value,
                'payment_state_reason' => $paymentPack['reason'],
                'payment_das_count' => $paymentPack['das_count'],
                'payment_unpaid_count' => $paymentPack['unpaid_count'],
                'payment_paid_count' => $paymentPack['paid_count'],
                'payment_open_competencies' => $openPaymentCompetencies[$cid] ?? [],
                'latest_declaration' => $lastPublic === null ? null : [
                    'period_key' => $lastPublic['period_key'] ?? null,
                    'declaration_number' => $lastPublic['declaration_number'] ?? null,
                    'normalized_operation_type' => $lastPublic['normalized_operation_type'] ?? null,
                    'transmitted_at' => $lastPublic['transmitted_at'] ?? null,
                ],
                'rbt12' => $rbtPublic,
                'documents' => $documents->get($cid, collect())
                    ->map(static fn (PgdasdArtifact $artifact): array => $artifact->toTenantDocumentArray())
                    ->values()
                    ->all(),
                'last_valid_query_at' => $proj?->last_valid_query_at?->toIso8601String(),
                'calendar_verified' => (bool) ($proj?->pgdasd_calendar_verified ?? false),
                'communication' => $comm,
                'links' => [
                    'history' => "/api/v1/fiscal/simples-mei/pgdasd/clients/{$cid}/history",
                    'preferences' => "/api/v1/fiscal/simples-mei/pgdasd/clients/{$cid}/communication-preference",
                    'preview' => "/api/v1/fiscal/simples-mei/pgdasd/clients/{$cid}/communication-preview",
                    'tracking' => "/api/v1/fiscal/simples-mei/pgdasd/clients/{$cid}/communications",
                ],
            ];
        }

        return $map;
    }

    /**
     * Competências DAS em aberto conhecidas localmente, sem consulta externa.
     *
     * @param  list<int>  $clientIds
     * @return array<int, list<array{period_key: string, amount_cents: ?int}>>
     */
    private function openPaymentCompetencies(Tenant $tenant, array $clientIds): array
    {
        $ttl = max(60, (int) config(
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
            86_400,
        ));
        $freshAfter = now()->subSeconds($ttl);
        $operations = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->whereNotNull('period_key')
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->get([
                'client_id',
                'period_key',
                'das_number',
                'pagtoweb_payment_status',
                'pagtoweb_verified_at',
                'pagtoweb_amount_cents',
                'amount_cents',
            ])
            ->groupBy(static fn (PgdasdOperation $operation): string => (
                (int) $operation->client_id
            ).'|'.(string) $operation->period_key)
            ->filter(static function ($periodOperations) use ($freshAfter): bool {
                if ($periodOperations->contains(
                    static fn (PgdasdOperation $operation): bool => $operation->pagtoweb_payment_status === 'PAID'
                )) {
                    return false;
                }

                return $periodOperations->isNotEmpty()
                    && $periodOperations->every(
                        static fn (PgdasdOperation $operation): bool => $operation->pagtoweb_payment_status === 'NOT_FOUND'
                            && $operation->pagtoweb_verified_at !== null
                            && $operation->pagtoweb_verified_at->greaterThanOrEqualTo($freshAfter)
                    );
            })
            ->flatten(1)
            ->values();

        if ($operations->isEmpty()) {
            return [];
        }

        $dasNumbers = $operations
            ->pluck('das_number')
            ->filter(static fn ($number): bool => is_string($number) && $number !== '')
            ->unique()
            ->values()
            ->all();

        $guideAmounts = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->when(
                $dasNumbers !== [],
                fn ($query) => $query->whereIn('identifier_code', $dasNumbers),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->orderByDesc('id')
            ->get(['client_id', 'identifier_code', 'amount_cents'])
            ->unique(static fn (TaxGuide $guide): string => $guide->client_id.'|'.$guide->identifier_code)
            ->mapWithKeys(static fn (TaxGuide $guide): array => [
                $guide->client_id.'|'.$guide->identifier_code => $guide->amount_cents,
            ]);

        $operationAmounts = [];
        foreach ($operations as $operation) {
            $dasNumber = (string) ($operation->das_number ?? '');
            if ($dasNumber === '' || $operation->amount_cents === null) {
                continue;
            }
            $key = ((int) $operation->client_id).'|'.$dasNumber;
            $operationAmounts[$key] ??= (int) $operation->amount_cents;
        }

        $gaps = [];
        foreach ($operations as $operation) {
            $clientId = (int) $operation->client_id;
            $dasNumber = (string) ($operation->das_number ?? '');
            if ($dasNumber === '') {
                continue;
            }
            $key = $clientId.'|'.$dasNumber;
            if ($operation->pagtoweb_amount_cents === null
                && $guideAmounts->get($key) === null
                && ! isset($operationAmounts[$key])
            ) {
                $gaps[$key] = [
                    'client_id' => $clientId,
                    'das_number' => $dasNumber,
                ];
            }
        }

        $gerarDasAmounts = $this->gerarDasLocalAmounts($tenant, array_values($gaps));

        $aggregate = [];
        foreach ($operations as $operation) {
            $clientId = (int) $operation->client_id;
            $periodKey = (string) $operation->period_key;
            $dasNumber = (string) ($operation->das_number ?? '');
            $key = $clientId.'|'.$dasNumber;
            $amount = $dasNumber === ''
                ? null
                : ($operation->pagtoweb_amount_cents
                    ?? $guideAmounts->get($key)
                    ?? $operationAmounts[$key]
                    ?? $gerarDasAmounts[$key]
                    ?? null);

            if (! isset($aggregate[$clientId][$periodKey])) {
                $aggregate[$clientId][$periodKey] = [
                    'amount_cents' => null,
                    'has_missing_amount' => false,
                ];
            }

            if ($amount === null) {
                $aggregate[$clientId][$periodKey]['has_missing_amount'] = true;

                continue;
            }

            $resolved = (int) $amount;
            $current = $aggregate[$clientId][$periodKey]['amount_cents'];
            $aggregate[$clientId][$periodKey]['amount_cents'] = $current === null
                ? $resolved
                : max($current, $resolved);
        }

        $result = [];
        foreach ($aggregate as $clientId => $periods) {
            krsort($periods);
            $result[$clientId] = array_map(
                static fn (array $entry, string $periodKey): array => [
                    'period_key' => $periodKey,
                    'amount_cents' => $entry['has_missing_amount'] ? null : $entry['amount_cents'],
                ],
                $periods,
                array_keys($periods),
            );
        }

        return $result;
    }

    /**
     * Fallback local: snapshot/evidência GERAR_DAS (sem SERPRO).
     *
     * @param  list<array{client_id: int, das_number: string}>  $gaps
     * @return array<string, int> keyed by "{client_id}|{das_number}"
     */
    private function gerarDasLocalAmounts(Tenant $tenant, array $gaps): array
    {
        if ($gaps === []) {
            return [];
        }

        $needed = [];
        foreach ($gaps as $gap) {
            $needed[$gap['client_id'].'|'.$gap['das_number']] = true;
        }
        $clientIds = array_values(array_unique(array_map(
            static fn (array $gap): int => $gap['client_id'],
            $gaps,
        )));
        $resolved = [];

        $snapshots = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('service_code', 'PGDASD')
            ->where('operation_code', 'GERAR_DAS')
            ->orderByDesc('id')
            ->get(['client_id', 'normalized']);

        foreach ($snapshots as $snapshot) {
            $parsed = $this->parseGerarDasAmount(
                is_array($snapshot->normalized) ? $snapshot->normalized : null
            );
            if ($parsed === null) {
                continue;
            }
            $key = ((int) $snapshot->client_id).'|'.$parsed['das_number'];
            if (! isset($needed[$key]) || isset($resolved[$key])) {
                continue;
            }
            $resolved[$key] = $parsed['amount_cents'];
        }

        $stillNeeded = array_diff_key($needed, $resolved);
        if ($stillNeeded === []) {
            return $resolved;
        }

        $runs = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('client_id', $clientIds)
            ->where('service_code', 'PGDASD')
            ->where('operation_code', 'GERAR_DAS')
            ->where(function ($query): void {
                $query->where('result', FiscalRunResult::Success->value)
                    ->orWhere('status', FiscalRunStatus::Completed->value);
            })
            ->orderByDesc('id')
            ->get(['id', 'client_id']);

        if ($runs->isEmpty()) {
            return $resolved;
        }

        $runClientIds = $runs->mapWithKeys(
            static fn (FiscalMonitoringRun $run): array => [(int) $run->id => (int) $run->client_id]
        );

        $artifacts = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('run_id', $runs->pluck('id'))
            ->where('content_type', 'application/json')
            ->orderByDesc('id')
            ->get();

        foreach ($artifacts as $artifact) {
            $clientId = $runClientIds[(int) $artifact->run_id] ?? null;
            if ($clientId === null) {
                continue;
            }
            try {
                $bytes = $this->evidenceStore->readAuthorized($artifact, (int) $tenant->id);
            } catch (Throwable) {
                continue;
            }
            $json = json_decode($bytes, true);
            if (! is_array($json)) {
                continue;
            }
            $parsed = $this->parseGerarDasAmount($json);
            if ($parsed === null) {
                continue;
            }
            $key = $clientId.'|'.$parsed['das_number'];
            if (! isset($stillNeeded[$key]) || isset($resolved[$key])) {
                continue;
            }
            $resolved[$key] = $parsed['amount_cents'];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array{das_number: string, amount_cents: int}|null
     */
    private function parseGerarDasAmount(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if (isset($data['dados']) && is_array($data['dados'])) {
            $nested = $this->parseGerarDasAmount($data['dados']);
            if ($nested !== null) {
                return $nested;
            }
        }

        $doc = null;
        foreach (['document_number', 'numeroDocumento', 'numero_documento'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $candidate = trim((string) $data[$key]);
            if ($candidate !== '') {
                $doc = $candidate;
                break;
            }
        }

        $amount = null;
        foreach (['amount', 'total', 'principal', 'valor'] as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (! is_numeric($data[$key])) {
                continue;
            }
            $amount = (float) $data[$key];
            break;
        }

        if ($doc === null || $amount === null || $amount < 0) {
            return null;
        }

        return [
            'das_number' => $doc,
            'amount_cents' => (int) round($amount * 100),
        ];
    }

    /**
     * Histórico local — contrato SPA (periods + provenance). Sem SERPRO.
     *
     * @return array<string, mixed>
     */
    public function history(Tenant $tenant, Client $client, ?int $year = null): array
    {
        $this->assertClient($tenant, $client);
        if ($year !== null && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Ano do histórico inválido.');
        }
        $definitionId = TaxObligationDefinition::query()->where('code', 'PGDAS_D')->value('id');

        $operations = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereHas('projection', fn ($query) => $query->where('obligation_definition_id', $definitionId ?? 0))
            ->when($year !== null, function ($q) use ($year): void {
                $q->where('period_key', 'like', sprintf('%04d-%%', $year));
            })
            ->orderByDesc('period_key')
            ->orderByDesc('transmitted_at')
            ->orderByDesc('id')
            ->get();

        $artifacts = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->with('evidenceArtifact')
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereHas('projection', function ($query) use ($definitionId, $year): void {
                $query->where('obligation_definition_id', $definitionId ?? 0)
                    ->when($year !== null, fn ($inner) => $inner->where('period_key', 'like', sprintf('%04d-%%', $year)));
            })
            ->orderByDesc('observed_at')
            ->get();

        $rbt12 = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereHas('projection', function ($query) use ($definitionId, $year): void {
                $query->where('obligation_definition_id', $definitionId ?? 0)
                    ->when($year !== null, fn ($inner) => $inner->where('period_key', 'like', sprintf('%04d-%%', $year)));
            })
            ->orderByDesc('id')
            ->get();

        $proj = $this->currentProjection($tenant, $client);
        $tz = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $expectedPeriodKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $tz));

        // Agrupa por period_key no formato esperado pelo SPA
        $byPeriod = [];
        foreach ($operations as $op) {
            $pk = (string) ($op->period_key ?: 'unknown');
            if (! isset($byPeriod[$pk])) {
                $byPeriod[$pk] = [
                    'period_key' => $pk,
                    'declaration_state' => null,
                    'last_valid_query_at' => null,
                    'declarations' => [],
                    'das' => [],
                    'artifacts' => [],
                    'rbt12' => null,
                ];
            }
            if ($op->operationKind() === PgdasdOperationKind::Declaration) {
                $byPeriod[$pk]['declarations'][] = [
                    'period_key' => $op->period_key,
                    'declaration_number' => $op->declaration_number,
                    'number' => $op->declaration_number,
                    'operation_type' => $op->normalized_operation_type ?? $op->raw_operation_type,
                    'transmitted_at' => $op->transmitted_at?->toIso8601String(),
                    'malha' => $op->malha,
                ];
            } elseif ($op->operationKind() === PgdasdOperationKind::Das) {
                $byPeriod[$pk]['das'][] = [
                    'das_number' => $op->das_number,
                    'issued_at' => $op->issued_at?->toIso8601String(),
                    'payment_located' => $op->payment_located,
                    'payment_observation' => $op->payment_located === false
                        ? 'Pagamento não localizado até a consulta.'
                        : ($op->payment_located === true ? 'Pagamento localizado até a consulta.' : null),
                    'payment_observed_at' => $op->payment_observed_at?->toIso8601String(),
                ];
            }
        }

        foreach ($artifacts as $art) {
            $pk = is_array($art->metadata) ? (string) ($art->metadata['period_key'] ?? '') : '';
            if ($pk === '' || ! isset($byPeriod[$pk])) {
                // artefatos sem PA conhecido entram no PA esperado ou bucket vazio
                $pk = $expectedPeriodKey;
                if (! isset($byPeriod[$pk])) {
                    $byPeriod[$pk] = [
                        'period_key' => $pk,
                        'declaration_state' => null,
                        'last_valid_query_at' => null,
                        'declarations' => [],
                        'das' => [],
                        'artifacts' => [],
                        'rbt12' => null,
                    ];
                }
            }
            $doc = $art->toPublicArray();
            $byPeriod[$pk]['artifacts'][] = $doc;
        }

        foreach ($rbt12 as $r) {
            $pk = is_array($r->metadata) ? (string) ($r->metadata['period_key'] ?? '') : '';
            if ($pk === '') {
                $pk = $expectedPeriodKey;
            }
            if (! isset($byPeriod[$pk])) {
                $byPeriod[$pk] = [
                    'period_key' => $pk,
                    'declaration_state' => null,
                    'last_valid_query_at' => null,
                    'declarations' => [],
                    'das' => [],
                    'artifacts' => [],
                    'rbt12' => null,
                ];
            }
            if ($byPeriod[$pk]['rbt12'] === null) {
                $byPeriod[$pk]['rbt12'] = $r->toPublicArray();
            }
        }

        // Estado do PA esperado na projeção
        $state = $proj?->pgdasd_declaration_state?->value
            ?? PgdasdDeclarationState::Unverified->value;
        $lastValid = $proj?->last_valid_query_at?->toIso8601String();
        if (isset($byPeriod[$expectedPeriodKey])) {
            $byPeriod[$expectedPeriodKey]['declaration_state'] = $state;
            $byPeriod[$expectedPeriodKey]['declaration_state_reason'] = $this->stateReason($proj);
            $byPeriod[$expectedPeriodKey]['last_valid_query_at'] = $lastValid;
        }

        krsort($byPeriod);
        $periods = array_values($byPeriod);

        return [
            'client' => [
                'id' => $client->id,
                'legal_name' => $client->legal_name,
                'cnpj_masked' => $this->historyCnpjMasked($client),
            ],
            'expected_period_key' => $expectedPeriodKey,
            'declaration_state' => $state,
            'declaration_state_reason' => $this->stateReason($proj),
            'last_valid_query_at' => $lastValid,
            'periods' => $periods,
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ];
    }

    public function findArtifact(Tenant $tenant, Client $client, int $artifactId): ?PgdasdArtifact
    {
        return PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->with('evidenceArtifact')
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereKey($artifactId)
            ->first();
    }

    public function findArtifactForTenant(Tenant $tenant, int $artifactId): ?PgdasdArtifact
    {
        return PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->with('evidenceArtifact')
            ->where('tenant_id', $tenant->id)
            ->whereKey($artifactId)
            ->first();
    }

    /**
     * Enfileira coleta documental explícita (14 ou 15) — faturável.
     *
     * @param  array<string, mixed>  $params
     */
    public function enqueueDocumentCollect(
        Tenant $tenant,
        Client $client,
        string $operation,
        array $params,
        ?int $actorId,
    ): FiscalMonitoringRun {
        $this->assertClient($tenant, $client);

        $op = strtoupper($operation);
        $operationCode = match ($op) {
            '14', 'CONSULTIMADECREC', 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO' => 'CONSULTAR_ULTIMA_DECLARACAO_RECIBO',
            '15', 'CONSDECREC', 'CONSULTAR_RECIBO' => 'CONSULTAR_RECIBO',
            default => throw new RuntimeException('Operação documental PGDAS-D inválida.'),
        };

        $periodKey = trim((string) ($params['period_key'] ?? ''));
        try {
            PgdasdPeriod::parse($periodKey);
        } catch (Throwable) {
            throw new RuntimeException('PA inválido para coleta documental.');
        }
        if ($operationCode === 'CONSULTAR_RECIBO') {
            $declarationNumber = trim((string) ($params['numeroDeclaracao'] ?? ''));
            if ($declarationNumber === '') {
                throw new RuntimeException('Número da declaração é obrigatório para o serviço 15.');
            }

            $observed = PgdasdOperation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('kind', PgdasdOperationKind::Declaration->value)
                ->where('declaration_number', $declarationNumber)
                ->orderByDesc('transmitted_at')
                ->orderByDesc('id')
                ->first();
            if ($observed === null) {
                throw new RuntimeException(
                    'Declaração não observada em consulta válida do serviço 13 para este cliente.'
                );
            }
            if ((string) $observed->period_key !== $periodKey) {
                throw new RuntimeException('O PA informado não corresponde à declaração observada.');
            }
        }

        $run = $this->runs->enqueueManual(
            tenant: $tenant,
            client: $client,
            systemCode: 'INTEGRA_SN',
            serviceCode: 'PGDASD',
            operationCode: $operationCode,
            actorId: $actorId,
            dispatch: false,
        );

        $progress = is_array($run->progress) ? $run->progress : [];
        if (isset($params['periodoApuracao'])) {
            $progress['periodo_apuracao'] = (string) $params['periodoApuracao'];
            try {
                $progress['period_key'] = PgdasdPeriod::periodKeyFromPeriodoApuracao((string) $params['periodoApuracao']);
            } catch (Throwable) {
                // ignore
            }
        }
        if (isset($params['period_key'])) {
            $progress['period_key'] = (string) $params['period_key'];
        }
        if (isset($params['numeroDeclaracao'])) {
            $progress['numero_declaracao'] = (string) $params['numeroDeclaracao'];
        }
        if (isset($params['numeroDas'])) {
            $progress['numero_das'] = (string) $params['numeroDas'];
        }
        $run->forceFill(['progress' => $progress])->save();
        ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->fresh() ?? $run;
    }

    public function enqueueAutomaticRbt12Extract(
        PgdasdRbt12Projection $rbt12,
    ): FiscalMonitoringRun {
        $projection = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->find($rbt12->projection_id);
        $client = Client::query()
            ->withoutGlobalScopes()
            ->find($rbt12->client_id);
        $tenant = Tenant::query()->find($rbt12->tenant_id);
        if ($projection === null || $client === null || $tenant === null
            || (int) $projection->tenant_id !== (int) $tenant->id
            || (int) $client->tenant_id !== (int) $tenant->id
            || $rbt12->status?->value !== 'PENDING'
        ) {
            throw new RuntimeException('Reserva RBT12 inválida para consulta documental.');
        }

        $hasDas = is_string($rbt12->source_das_number) && $rbt12->source_das_number !== '';
        $declarationNumber = is_string($rbt12->source_declaration_number)
            ? trim($rbt12->source_declaration_number)
            : '';
        if (! $hasDas && $declarationNumber === '') {
            throw new RuntimeException('Reserva RBT12 sem DAS nem declaração para consulta documental.');
        }

        $originRunId = $rbt12->source_run_id;
        $priorMetadata = is_array($rbt12->metadata) ? $rbt12->metadata : [];
        $priorExtractRunId = isset($priorMetadata['extract_run_id'])
            ? (int) $priorMetadata['extract_run_id']
            : null;

        $correlationId = 'pgdasd-rbt12-'.substr((string) $rbt12->source_reference_key, 0, 50);
        $operationCode = $hasDas ? 'CONSULTAR_EXTRATO' : 'CONSULTAR_RECIBO';
        $operationKey = $hasDas ? 'pgdasd.consextrato' : 'pgdasd.consdecrec';

        $run = $this->runs->enqueueManual(
            tenant: $tenant,
            client: $client,
            systemCode: 'INTEGRA_SN',
            serviceCode: 'PGDASD',
            operationCode: $operationCode,
            correlationId: $correlationId,
            dispatch: false,
        );
        $progress = [
            'period_key' => $projection->period_key,
            'periodo_apuracao' => str_replace('-', '', (string) $projection->period_key),
            'rbt12_projection_id' => (int) $rbt12->id,
            'rbt12_source_reference_key' => $rbt12->source_reference_key,
        ];
        if ($hasDas) {
            $progress['numero_das'] = $rbt12->source_das_number;
        } else {
            $progress['numero_declaracao'] = $declarationNumber;
        }
        $run->forceFill([
            'operation_key' => $operationKey,
            'progress' => $progress,
        ])->save();

        $metadata = $priorMetadata;
        $metadata['reservation_run_id'] ??= $originRunId;
        $metadata['extract_run_id'] = $run->id;
        $metadata['source_kind'] = $hasDas ? 'das_extrato' : 'declaration_recibo';
        $rbt12->forceFill([
            'source_run_id' => $run->id,
            'metadata' => $metadata,
        ])->save();

        // Correlação determinística reutiliza a mesma run: não re-despachar se
        // já estava vinculada e não está FAILED (em voo ou terminal de sucesso).
        $status = $run->status instanceof FiscalRunStatus
            ? $run->status
            : FiscalRunStatus::tryFrom((string) ($run->status ?? ''));
        $alreadyLinked = $priorExtractRunId !== null && $priorExtractRunId === (int) $run->id;

        if ($alreadyLinked && $status !== null && $status !== FiscalRunStatus::Failed) {
            return $run->fresh() ?? $run;
        }

        if ($status === FiscalRunStatus::Failed) {
            $run->forceFill([
                'status' => FiscalRunStatus::Queued,
                'result' => null,
                'error_code' => null,
                'error_message' => null,
                'skip_reason' => null,
                'finished_at' => null,
                'started_at' => null,
                'lease_owner' => null,
                'locked_at' => null,
                'situation' => FiscalSituation::Unknown,
            ])->save();

            ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

            return $run->fresh() ?? $run;
        }

        if ($status !== null && $status !== FiscalRunStatus::Queued) {
            return $run->fresh() ?? $run;
        }

        ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return $run->fresh() ?? $run;
    }

    private function currentProjection(Tenant $tenant, Client $client): ?TaxObligationProjection
    {
        $tz = is_string($tenant->timezone) && $tenant->timezone !== ''
            ? $tenant->timezone
            : 'America/Sao_Paulo';
        $periodKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $tz));

        $def = TaxObligationDefinition::query()
            ->where('code', 'PGDAS_D')
            ->first();

        $q = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('period_key', $periodKey);

        $q->where('obligation_definition_id', $def?->id ?? 0);

        return $q->first();
    }

    private function assertClient(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }

    private function stateReason(?TaxObligationProjection $projection): string
    {
        if ($projection === null || $projection->last_valid_query_at === null) {
            return 'NO_VALID_QUERY';
        }
        $metadata = is_array($projection->metadata) ? $projection->metadata : [];
        if (is_string($metadata['pgdasd_declaration_state_reason'] ?? null)) {
            return $metadata['pgdasd_declaration_state_reason'];
        }

        return match ($projection->pgdasd_declaration_state?->value) {
            'CURRENT' => 'EXPECTED_PA_FOUND',
            'DUE_WITHIN_DEADLINE' => 'WITHIN_DEADLINE',
            'OVERDUE_NOT_FOUND' => 'ABSENT_AFTER_VERIFIED_DEADLINE',
            default => 'UNVERIFIED',
        };
    }

    private function maskCnpj(?string $cnpj): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $cnpj)) ?? '';
        if (strlen($normalized) < 8) {
            return '****';
        }

        return substr($normalized, 0, 4)
            .str_repeat('*', max(4, strlen($normalized) - 8))
            .(strlen($normalized) > 8 ? substr($normalized, -4) : '');
    }

    private function historyCnpjMasked(Client $client): string
    {
        $cnpj = $client->establishments()
            ->orderByDesc('is_headquarters')
            ->orderBy('id')
            ->value('cnpj');

        return $this->maskCnpj(is_string($cnpj) && $cnpj !== '' ? $cnpj : $client->root_cnpj);
    }
}
