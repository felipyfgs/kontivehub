<?php

namespace App\Jobs\Fiscal;

use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job tenant-scoped: sincroniza uma competência FGTS/eSocial.
 * Apenas EsocialEventClient (fake/M2M) — sem portal humano ou automação de browser.
 */
class SyncFgtsEsocialCompetenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $officeId,
        public int $clientId,
        public string $competencePeriodKey,
        public ?int $establishmentId = null,
        public ?int $runId = null,
    ) {
        $this->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
    }

    public function handle(
        FgtsEsocialMonitoringService $monitoring,
        EsocialBxReadinessService $readinessService,
    ): void {
        $office = Office::query()->find($this->officeId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $this->officeId)
            ->whereKey($this->clientId)
            ->first();

        if ($office === null || $client === null) {
            Log::warning('fgts_esocial.job_missing_tenant', [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
            ]);

            return;
        }

        $readiness = $readinessService->check($office, $client);
        if (! $readiness->ready) {
            Log::info('fgts_esocial.job_skipped_not_ready', [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
                'reason' => $readiness->blockers[0]['code'] ?? 'ESOCIAL_BX_NOT_READY',
            ]);

            return;
        }

        $establishment = null;
        if ($this->establishmentId !== null) {
            $establishment = Establishment::query()->withoutGlobalScopes()
                ->where('office_id', $this->officeId)
                ->where('client_id', $this->clientId)
                ->whereKey($this->establishmentId)
                ->first();
        }

        try {
            $monitoring->syncCompetence(
                office: $office,
                client: $client,
                competencePeriodKey: $this->competencePeriodKey,
                establishment: $establishment,
            );
        } catch (EsocialBxException $exception) {
            Log::warning('fgts_esocial.job_failed', [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
                'competence' => $this->competencePeriodKey,
                ...$exception->toSanitizedArray(),
            ]);

            if ($exception->retryable && ! $exception->blocked) {
                throw $exception;
            }
        } catch (Throwable $e) {
            Log::warning('fgts_esocial.job_failed', [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
                'competence' => $this->competencePeriodKey,
                'code' => 'ESOCIAL_BX_INTERNAL_ERROR',
                'exception_class' => $e::class,
            ]);
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
            'office:'.$this->officeId,
            'client:'.$this->clientId,
        ];
    }
}
