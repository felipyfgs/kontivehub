<?php

namespace App\Jobs\Fiscal;

use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job tenant-scoped: sincroniza uma competência FGTS/eSocial.
 * Apenas EsocialEventClient (fake/M2M) — sem portal humano ou automação de browser.
 */
final class SyncFgtsEsocialCompetenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $tenantId,
        public int $clientId,
        public string $competencePeriodKey,
        public ?int $establishmentId = null,
        public ?int $runId = null,
    ) {
        $this->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        $this->afterCommit();
    }

    public function handle(
        FgtsEsocialMonitoringService $monitoring,
        EsocialBxReadinessService $readinessService,
        ?FiscalMonitoringRunService $runs = null,
    ): void {
        if ($this->runId !== null) {
            $this->executeRun($runs ?? app(FiscalMonitoringRunService::class));

            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->clientId)
            ->first();

        if ($tenant === null || $client === null) {
            Log::warning('fgts_esocial.job_missing_tenant', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
            ]));

            return;
        }

        $readiness = $readinessService->check($tenant, $client);
        if (! $readiness->ready) {
            Log::info('fgts_esocial.job_skipped_not_ready', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'reason' => $readiness->blockers[0]['code'] ?? 'ESOCIAL_BX_NOT_READY',
            ]));

            return;
        }

        $establishment = null;
        if ($this->establishmentId !== null) {
            $establishment = Establishment::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('client_id', $this->clientId)
                ->whereKey($this->establishmentId)
                ->first();
            if ($establishment === null) {
                Log::warning('fgts_esocial.job_missing_establishment', LogSanitizer::redact([
                    'tenant_id' => $this->tenantId,
                    'client_id' => $this->clientId,
                    'establishment_id' => $this->establishmentId,
                ]));

                return;
            }
        }

        try {
            $monitoring->syncCompetence(
                tenant: $tenant,
                client: $client,
                competencePeriodKey: $this->competencePeriodKey,
                establishment: $establishment,
            );
        } catch (EsocialBxException $exception) {
            Log::warning('fgts_esocial.job_failed', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'competence' => $this->competencePeriodKey,
                ...$exception->toSanitizedArray(),
            ]));

            if ($exception->retryable && ! $exception->blocked) {
                throw $exception;
            }
        } catch (Throwable $e) {
            Log::warning('fgts_esocial.job_failed', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'competence' => $this->competencePeriodKey,
                'code' => 'ESOCIAL_BX_INTERNAL_ERROR',
                'exception_class' => $e::class,
            ]));
            throw $e;
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Tags/métricas sem CNPJ completo.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'fgts-esocial',
            'tenant:'.$this->tenantId,
            'client:'.$this->clientId,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->runId !== null) {
            app(FiscalMonitoringRunService::class)->failUnhandledJob(
                $this->runId,
                $exception === null
                    ? null
                    : LogSanitizer::scrubString(
                        mb_substr($exception::class.': '.$exception->getMessage(), 0, 500),
                    ),
            );
        }

        Log::warning('fgts_esocial.job_terminal_failure', LogSanitizer::redact([
            'tenant_id' => $this->tenantId,
            'client_id' => $this->clientId,
            'run_id' => $this->runId,
            'code' => 'ESOCIAL_BX_JOB_FAILED',
            'exception_class' => $exception === null ? null : $exception::class,
        ]));
    }

    private function executeRun(FiscalMonitoringRunService $runs): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $run = $tenant === null
            ? null
            : $runs->findForTenant($tenant, $this->runId);
        if ($run === null || (int) $run->client_id !== $this->clientId) {
            Log::warning('fgts_esocial.job_missing_run', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'run_id' => $this->runId,
            ]));

            return;
        }

        try {
            $runs->execute($this->runId);
        } catch (Throwable $exception) {
            Log::warning('fgts_esocial.run_job_failed', LogSanitizer::redact([
                'tenant_id' => $this->tenantId,
                'client_id' => $this->clientId,
                'run_id' => $this->runId,
                'code' => 'ESOCIAL_BX_RUN_EXECUTION_FAILED',
                'exception_class' => $exception::class,
            ]));

            throw $exception;
        }
    }
}
