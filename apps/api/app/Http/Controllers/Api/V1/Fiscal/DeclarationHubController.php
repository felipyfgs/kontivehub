<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Enums\TaxDeliveryEvidenceKind;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TaxObligationDefinition;
use App\Services\Fiscal\Declarations\DeclarationDctfwebEnrichmentService;
use App\Services\Fiscal\Declarations\DeclarationHubQueryService;
use App\Services\Fiscal\Declarations\DeclarationIntegrationCoverageService;
use App\Services\Fiscal\Declarations\DeclarationOperationCatalogService;
use App\Services\Fiscal\Declarations\DeclarationPgdasdEnrichmentService;
use App\Services\Fiscal\Declarations\TaxDeadlineCalendarService;
use App\Services\Fiscal\Declarations\TaxDeliveryEvidenceService;
use App\Services\Fiscal\Declarations\TaxObligationCatalogService;
use App\Services\Fiscal\Declarations\TaxObligationProjectionService;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Central agregada de declarações (tenant-scoped) — task 11.5.
 */
class DeclarationHubController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TaxObligationCatalogService $catalog,
        private readonly TaxObligationProjectionService $projections,
        private readonly TaxDeliveryEvidenceService $evidences,
        private readonly TaxDeadlineCalendarService $deadlines,
        private readonly DeclarationHubQueryService $hub,
        private readonly DeclarationPgdasdEnrichmentService $pgdasdEnrichment,
        private readonly DeclarationDctfwebEnrichmentService $dctfwebEnrichment,
        private readonly DeclarationIntegrationCoverageService $integrationCoverage,
        private readonly DeclarationOperationCatalogService $operationCatalog,
    ) {}

    /** Catálogo versionado (global, leitura). */
    public function catalog(): JsonResponse
    {
        $this->assertCanRead();

        return response()->json([
            'data' => [
                'obligations' => $this->catalog->catalogPayload(),
                'calendar' => $this->catalog->currentCalendar()?->toPublicArray(),
                'integration_coverage' => $this->integrationCoverage->publicCoverage(),
                'operation_catalog' => $this->operationCatalog->publicCatalog(),
            ],
        ]);
    }

    /** Lista agregada com filtros e deep-links. */
    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $filters = [
            'client_id' => $request->query('client_id'),
            'obligation_code' => $request->query('obligation_code'),
            'module_key' => $request->query('module_key'),
            'period_key' => $request->query('period_key'),
            'period_year' => $request->query('period_year'),
            'period_month' => $request->query('period_month'),
            'applicability' => $request->query('applicability'),
            'situation' => $request->query('situation'),
            'delivery_status' => $request->query('delivery_status'),
            'competence_id' => $request->query('competence_id'),
            'per_page' => $request->query('per_page', 50),
        ];

        if ($request->query->has('is_open')) {
            $filters['is_open'] = filter_var($request->query('is_open'), FILTER_VALIDATE_BOOL);
        }

        $page = $this->hub->list($office, $filters);
        $enriched = $this->pgdasdEnrichment->enrichPublicList($office, $page->getCollection(), true);
        $clientId = is_numeric($filters['client_id'] ?? null) ? (int) $filters['client_id'] : null;
        $enriched = $this->dctfwebEnrichment->enrichPublicRows($office, $enriched, $clientId);
        $page->setCollection(collect($enriched));

        return response()->json($page);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $clientId = $request->query('client_id');
        $periodKey = $request->query('period_key');

        return response()->json([
            'data' => $this->hub->summaryByObligation(
                $office,
                is_numeric($clientId) ? (int) $clientId : null,
                is_string($periodKey) ? $periodKey : null,
            ),
        ]);
    }

    public function show(int $projection): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $model = $this->hub->find($office, $projection);
        if ($model === null) {
            return response()->json(['message' => 'Projeção de declaração não encontrada.'], 404);
        }

        $data = $model->toPublicArray(true);
        $data['evidences'] = $model->evidences
            ->map(fn ($e) => $e->toPublicArray())
            ->values()
            ->all();
        $data['due_rule_snapshot'] = $model->due_rule_snapshot;
        $data['due_history'] = $model->due_history;

        return response()->json(['data' => $data]);
    }

    /** Materializa projeção(ões) para contribuinte/competência. */
    public function project(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'max:20'],
            'obligation_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'period_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'all' => ['sometimes', 'boolean'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        try {
            if (! empty($data['all'])) {
                $items = $this->projections->projectAllForClient(
                    $office,
                    $client,
                    $data['period_key'],
                    $data['period_year'] ?? null,
                    $data['period_month'] ?? null,
                );

                return response()->json([
                    'data' => array_map(
                        fn ($p) => $p->toPublicArray(true),
                        $items,
                    ),
                ], 201);
            }

            $code = strtoupper((string) ($data['obligation_code'] ?? ''));
            if ($code === '') {
                return response()->json(['message' => 'obligation_code é obrigatório (ou all=true).'], 422);
            }

            $definition = TaxObligationDefinition::query()->where('code', $code)->first();
            if ($definition === null) {
                return response()->json(['message' => 'Obrigação não encontrada no catálogo.'], 404);
            }

            $projection = $this->projections->project(
                $office,
                $client,
                $definition,
                $data['period_key'],
                $data['period_year'] ?? null,
                $data['period_month'] ?? null,
            );

            return response()->json(['data' => $projection->toPublicArray(true)], 201);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Anexa recibo/protocolo/artefato interno à projeção. */
    public function attachEvidence(Request $request, int $projection): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $model = $this->hub->find($office, $projection);
        if ($model === null) {
            return response()->json(['message' => 'Projeção de declaração não encontrada.'], 404);
        }

        $data = $request->validate([
            'kind' => ['required', 'string', Rule::enum(TaxDeliveryEvidenceKind::class)],
            'protocol_number' => ['nullable', 'string', 'max:80'],
            'receipt_number' => ['nullable', 'string', 'max:80'],
            'source' => ['required', 'string', 'max:80'],
            'source_version' => ['nullable', 'string', 'max:40'],
            'observed_at' => ['nullable', 'date'],
            'evidence_artifact_id' => ['nullable', 'integer'],
            'run_id' => ['nullable', 'integer'],
            'payload_digest' => ['nullable', 'string', 'size:64'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $evidence = $this->evidences->attach($office, $model, [
                'kind' => TaxDeliveryEvidenceKind::from($data['kind']),
                'protocol_number' => $data['protocol_number'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? null,
                'source' => $data['source'],
                'source_version' => $data['source_version'] ?? null,
                'observed_at' => isset($data['observed_at'])
                    ? CarbonImmutable::parse($data['observed_at'])
                    : null,
                'evidence_artifact_id' => $data['evidence_artifact_id'] ?? null,
                'run_id' => $data['run_id'] ?? null,
                'payload_digest' => $data['payload_digest'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $fresh = $this->hub->find($office, $projection);

        return response()->json([
            'data' => [
                'evidence' => $evidence->toPublicArray(),
                'projection' => $fresh?->toPublicArray(true),
            ],
        ], 201);
    }

    public function showEvidence(int $projection, int $evidence): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $model = $this->hub->findEvidence($office, $projection, $evidence);
        if ($model === null) {
            return response()->json(['message' => 'Evidência não encontrada.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    /**
     * Publica prorrogação de calendário (ADMIN) e recalcula competências abertas.
     * Uso operacional/plataforma; tenants leem o efeito nas projeções.
     */
    public function publishCalendar(Request $request): JsonResponse
    {
        $this->assertCanAdmin();
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:160'],
            'source_ref' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'recalculate_open' => ['sometimes', 'boolean'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.obligation_code' => ['required', 'string', 'max:60'],
            'rules.*.period_granularity' => ['nullable', 'string', 'max:20'],
            'rules.*.due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'rules.*.due_month_offset' => ['nullable', 'integer', 'min:0', 'max:24'],
            'rules.*.fixed_due_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'rules.*.fixed_due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'rules.*.business_day_adjustment' => ['nullable', 'string', 'max:20'],
            'rules.*.timezone' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $this->deadlines->publishCalendarVersion(
                code: $data['code'] ?? 'RFB_NATIONAL',
                label: $data['label'],
                rules: $data['rules'],
                sourceRef: $data['source_ref'] ?? null,
                notes: $data['notes'] ?? null,
                timezone: $data['timezone'] ?? 'America/Sao_Paulo',
                recalculateOpen: $data['recalculate_open'] ?? true,
            );
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'calendar' => $result['calendar']->toPublicArray(),
                'recalculated' => $result['recalculated'],
            ],
        ], 201);
    }

    private function assertCanRead(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function assertCanAdmin(): void
    {
        $role = $this->currentOffice->role();
        if ($role !== OfficeRole::Admin) {
            abort(403, 'Somente ADMIN do escritório.');
        }
    }
}
