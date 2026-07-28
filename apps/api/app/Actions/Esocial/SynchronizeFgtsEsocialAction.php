<?php

namespace App\Actions\Esocial;

use App\DTO\Esocial\FgtsEsocialQueuedSyncData;
use App\DTO\Esocial\FgtsEsocialSyncData;
use App\DTO\Esocial\FgtsEsocialSyncResultData;
use App\Exceptions\FgtsEsocialApiException;
use App\Jobs\Fiscal\SyncFgtsEsocialCompetenceJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use App\Services\Esocial\FgtsEsocialQuery;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SynchronizeFgtsEsocialAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsEsocialQuery $query,
        private EsocialBxReadinessService $readiness,
        private FgtsEsocialMonitoringService $monitoring,
        private FiscalMonitoringRunService $runs,
    ) {}

    public function queue(
        User $actor,
        FgtsEsocialSyncData $data,
    ): FgtsEsocialQueuedSyncData {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $this->assertReady($tenant, $client);
        $establishment = $this->query->establishment(
            $client,
            $data->establishmentId,
        );

        $run = $data->createRun
            ? $this->createRun($actor, $client, $establishment, $data)
            : null;

        if ($run !== null && $run->wasRecentlyCreated) {
            $this->dispatch($client, $establishment, $data, $run);
        } elseif ($run === null && $data->dispatchJob) {
            $this->dispatch($client, $establishment, $data);
        }

        return new FgtsEsocialQueuedSyncData(
            clientId: (int) $client->id,
            competencePeriodKey: $data->competencePeriodKey,
            establishmentId: $establishment?->id,
            run: $run,
            coverage: $this->monitoring->coverageManifest(),
        );
    }

    public function execute(
        FgtsEsocialSyncData $data,
    ): FgtsEsocialSyncResultData {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $this->assertReady($tenant, $client);
        $establishment = $this->query->establishment(
            $client,
            $data->establishmentId,
        );

        try {
            $result = $this->monitoring->syncCompetence(
                tenant: $tenant,
                client: $client,
                competencePeriodKey: $data->competencePeriodKey,
                establishment: $establishment,
            );
        } catch (Throwable) {
            throw FgtsEsocialApiException::syncFailed();
        }

        if ($result['status']->is_quarantined) {
            throw FgtsEsocialApiException::syntheticDataQuarantined();
        }

        return new FgtsEsocialSyncResultData(
            status: $result['status'],
            projection: $result['projection'],
            eventsCount: $result['events_count'],
            evidences: $result['evidences'],
            coverage: $this->monitoring->coverageManifest(),
        );
    }

    private function createRun(
        User $actor,
        Client $client,
        ?Establishment $establishment,
        FgtsEsocialSyncData $data,
    ): FiscalMonitoringRun {
        try {
            return DB::transaction(function () use (
                $actor,
                $client,
                $establishment,
                $data,
            ): FiscalMonitoringRun {
                $tenant = $this->currentTenant->tenant();
                $category = FiscalCategory::query()
                    ->where('code', 'FGTS')
                    ->first();
                $competence = $category === null
                    ? null
                    : $this->competence($client, $category, $data);

                $run = $this->runs->enqueueManual(
                    tenant: $tenant,
                    client: $client,
                    systemCode: (string) config('fgts_esocial.system_code', 'ESOCIAL'),
                    serviceCode: (string) config('fgts_esocial.service_code', 'FGTS'),
                    operationCode: (string) config('fgts_esocial.operation_code', 'MONITOR'),
                    competence: $competence,
                    actorId: (int) $actor->id,
                    correlationId: $data->correlationId,
                    dispatch: false,
                );

                $progress = is_array($run->progress) ? $run->progress : [];
                if (($progress['competence_period_key'] ?? null) === null) {
                    $run->forceFill([
                        'progress' => array_merge($progress, [
                            'competence_period_key' => $data->competencePeriodKey,
                            'establishment_id' => $establishment?->id,
                        ]),
                    ])->save();
                }

                return $run;
            });
        } catch (Throwable) {
            throw FgtsEsocialApiException::runCreationFailed();
        }
    }

    private function competence(
        Client $client,
        FiscalCategory $category,
        FgtsEsocialSyncData $data,
    ): FiscalCompetence {
        [$year, $month] = array_map(
            'intval',
            explode('-', $data->competencePeriodKey),
        );

        return FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                [
                    'tenant_id' => $this->currentTenant->id(),
                    'client_id' => $client->id,
                    'fiscal_category_id' => $category->id,
                    'period_key' => $data->competencePeriodKey,
                ],
                [
                    'period_year' => $year,
                    'period_month' => $month,
                    'situation' => 'UNKNOWN',
                    'coverage' => 'PARTIAL',
                ],
            );
    }

    private function dispatch(
        Client $client,
        ?Establishment $establishment,
        FgtsEsocialSyncData $data,
        ?FiscalMonitoringRun $run = null,
    ): void {
        SyncFgtsEsocialCompetenceJob::dispatch(
            tenantId: $this->currentTenant->id(),
            clientId: (int) $client->id,
            competencePeriodKey: $data->competencePeriodKey,
            establishmentId: $establishment?->id,
            runId: $run?->id,
        )->afterCommit();
    }

    private function assertReady(
        Tenant $tenant,
        Client $client,
    ): void {
        $readiness = $this->readiness->check($tenant, $client);
        if (! $readiness->ready) {
            throw FgtsEsocialApiException::readinessBlocked($readiness);
        }
    }
}
