<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\DTO\Esocial\EsocialBxReadiness;
use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Jobs\Fiscal\SyncFgtsEsocialCompetenceJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * API tenant-scoped do monitoramento parcial FGTS via eSocial.
 * Respostas sempre incluem limitações de cobertura e NÃO expõem débito do portal.
 */
class FgtsEsocialController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly FgtsEsocialMonitoringService $monitoring,
        private readonly EsocialBxReadinessService $readiness,
        private readonly FiscalMonitoringRunService $runs,
    ) {}

    /**
     * Cobertura, limitações e estados independentes (texto explícito).
     */
    public function coverage(): JsonResponse
    {
        $this->assertCanRead();

        return response()->json([
            'data' => $this->monitoring->coverageManifest(),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $data = $request->validate(['client_id' => ['required', 'integer']]);
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        return response()->json(['data' => $this->readiness->check($office, $client)->toArray()]);
    }

    public function competences(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $competence = $request->query('competence_period_key');

        $page = $this->monitoring->paginateStatuses(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($competence) ? $competence : null,
        );

        $page->getCollection()->transform(fn ($row) => $row->toPublicArray());

        return response()->json($page);
    }

    public function showCompetence(int $status): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->monitoring->findStatusForOffice($office, $status);
        if ($model === null) {
            return response()->json(['message' => 'Competência FGTS não encontrada.'], 404);
        }

        $events = $this->monitoring->paginateEvents(
            $office,
            100,
            $model->client_id,
            $model->competence_period_key,
        );

        return response()->json([
            'data' => $model->toPublicArray(),
            'events' => $events->getCollection()->map(fn ($e) => $e->toPublicArray())->values(),
            'coverage' => $this->monitoring->coverageManifest(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $competence = $request->query('competence_period_key');
        $eventCode = $request->query('event_code');

        $page = $this->monitoring->paginateEvents(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($competence) ? $competence : null,
            is_string($eventCode) ? $eventCode : null,
        );

        $page->getCollection()->transform(fn ($e) => $e->toPublicArray());

        return response()->json([
            ...$page->toArray(),
            'coverage' => [
                'partial' => true,
                'limitations' => $this->monitoring->coverageManifest()['limitations'],
                'declares_fgts_digital_debt' => false,
            ],
        ]);
    }

    /**
     * Enfileira sincronização (job tenant-scoped) e/ou run do núcleo fiscal.
     */
    public function sync(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'competence_period_key' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'establishment_id' => ['sometimes', 'nullable', 'integer'],
            'dispatch_job' => ['sometimes', 'boolean'],
            'create_run' => ['sometimes', 'boolean'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $readiness = $this->readiness->check($office, $client);
        if (! $readiness->ready) {
            return $this->readinessBlockedResponse($readiness);
        }

        $establishment = null;
        if (! empty($data['establishment_id'])) {
            $establishment = Establishment::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->whereKey($data['establishment_id'])
                ->first();
            if ($establishment === null) {
                return response()->json(['message' => 'Estabelecimento não encontrado.'], 404);
            }
        }

        $dispatchJob = (bool) ($data['dispatch_job'] ?? true);
        $createRun = (bool) ($data['create_run'] ?? true);

        $run = null;
        if ($createRun) {
            try {
                $category = FiscalCategory::query()->where('code', 'FGTS')->first();
                $competence = null;
                if ($category !== null) {
                    [$year, $month] = array_map('intval', explode('-', $data['competence_period_key']));
                    $competence = FiscalCompetence::query()->withoutGlobalScopes()->firstOrCreate(
                        [
                            'office_id' => $office->id,
                            'client_id' => $client->id,
                            'fiscal_category_id' => $category->id,
                            'period_key' => $data['competence_period_key'],
                        ],
                        [
                            'period_year' => $year,
                            'period_month' => $month,
                            'situation' => 'UNKNOWN',
                            'coverage' => 'PARTIAL',
                        ],
                    );
                }

                // enqueueManual não aceita context; usamos competence no run.
                $run = $this->runs->enqueueManual(
                    office: $office,
                    client: $client,
                    systemCode: (string) config('fgts_esocial.system_code', 'ESOCIAL'),
                    serviceCode: (string) config('fgts_esocial.service_code', 'FGTS'),
                    operationCode: (string) config('fgts_esocial.operation_code', 'MONITOR'),
                    competence: $competence,
                    actorId: $request->user()?->id,
                    correlationId: $data['correlation_id'] ?? null,
                    dispatch: true,
                );

                // Progresso com competência para o adapter.
                if ($run->progress === null || ($run->progress['competence_period_key'] ?? null) === null) {
                    $run->forceFill([
                        'progress' => array_merge($run->progress ?? [], [
                            'competence_period_key' => $data['competence_period_key'],
                            'establishment_id' => $establishment?->id,
                        ]),
                    ])->save();
                }
            } catch (RuntimeException) {
                return response()->json([
                    'message' => 'Não foi possível criar a execução de monitoramento FGTS.',
                    'code' => 'ESOCIAL_RUN_CREATION_FAILED',
                ], 422);
            }
        }

        if ($dispatchJob) {
            SyncFgtsEsocialCompetenceJob::dispatch(
                officeId: (int) $office->id,
                clientId: (int) $client->id,
                competencePeriodKey: $data['competence_period_key'],
                establishmentId: $establishment?->id,
                runId: $run?->id,
            );
        }

        return response()->json([
            'data' => [
                'queued' => true,
                'client_id' => $client->id,
                'competence_period_key' => $data['competence_period_key'],
                'establishment_id' => $establishment?->id,
                'run' => $run?->toPublicArray(),
                'coverage' => $this->monitoring->coverageManifest(),
            ],
        ], 202);
    }

    /**
     * Sync síncrono (útil em testes/ops controlado) — ainda tenant-scoped.
     */
    public function syncNow(Request $request): JsonResponse
    {
        $this->assertCanWrite();
        $office = $this->currentOffice->office();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'competence_period_key' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'establishment_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($data['client_id'])
            ->first();

        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $readiness = $this->readiness->check($office, $client);
        if (! $readiness->ready) {
            return $this->readinessBlockedResponse($readiness);
        }

        $establishment = null;
        if (! empty($data['establishment_id'])) {
            $establishment = Establishment::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->whereKey($data['establishment_id'])
                ->first();
            if ($establishment === null) {
                return response()->json(['message' => 'Estabelecimento não encontrado.'], 404);
            }
        }

        try {
            $out = $this->monitoring->syncCompetence(
                office: $office,
                client: $client,
                competencePeriodKey: $data['competence_period_key'],
                establishment: $establishment,
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Falha sanitizada ao sincronizar o eSocial BX.',
                'code' => 'ESOCIAL_SYNC_FAILED',
            ], 502);
        }

        if ($out['status']->is_quarantined) {
            return response()->json([
                'message' => 'A sincronização produziu dados sintéticos em quarentena, indisponíveis para uso fiscal.',
                'code' => 'SYNTHETIC_FISCAL_DATA_QUARANTINED',
            ], 409);
        }

        return response()->json([
            'data' => [
                'status' => $out['status']->toPublicArray(),
                'projection' => $out['projection']->toArray(),
                'events_count' => $out['events_count'],
                'evidences' => array_map(
                    static fn ($e) => $e->toPublicArray(),
                    $out['evidences'],
                ),
                'coverage' => $this->monitoring->coverageManifest(),
            ],
        ]);
    }

    private function assertCanRead(): void
    {
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    private function readinessBlockedResponse(EsocialBxReadiness $readiness): JsonResponse
    {
        $blocker = $readiness->blockers[0] ?? [
            'code' => 'ESOCIAL_BX_NOT_READY',
            'message' => 'Provider eSocial BX indisponível.',
        ];

        return response()->json([
            'message' => $blocker['message'],
            'code' => $blocker['code'],
            'readiness' => $readiness->toArray(),
        ], $this->blockerHttpStatus($blocker['code']));
    }

    private function blockerHttpStatus(string $code): int
    {
        if ($code === 'ESOCIAL_BX_QUOTA_EXHAUSTED') {
            return 429;
        }
        if ($code === 'ESOCIAL_BX_CONCURRENT_REQUEST') {
            return 409;
        }
        if (str_starts_with($code, 'ESOCIAL_BX_CREDENTIAL_')) {
            return 422;
        }
        if ($code === 'ESOCIAL_BX_CLIENT_NOT_FOUND') {
            return 404;
        }
        if (in_array($code, [
            'ESOCIAL_BX_DISABLED',
            'ESOCIAL_BX_DRIVER_INVALID',
            'ESOCIAL_BX_ENVIRONMENT_INVALID',
            'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED',
            'ESOCIAL_BX_ENDPOINT_NOT_ALLOWED',
            'ESOCIAL_BX_LIMITS_INVALID',
            'ESOCIAL_BX_TIMEOUTS_INVALID',
            'ESOCIAL_BX_TIMEZONE_INVALID',
            'ESOCIAL_BX_BLOCKED_DAYS_INVALID',
        ], true)) {
            return 503;
        }

        return 423;
    }

    private function assertCanWrite(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
