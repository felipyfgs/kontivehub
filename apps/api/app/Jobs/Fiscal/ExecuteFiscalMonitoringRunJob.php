<?php

namespace App\Jobs\Fiscal;

use App\Models\FiscalMonitoringRun;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicy;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicyException;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdRbt12Service;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executa run de monitoramento com revalidação pré-chamada no service.
 * Identidade lógica está na run (idempotency_key); requeue cria continuação.
 */
class ExecuteFiscalMonitoringRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public int $fiscalMonitoringRunId,
    ) {
        $this->timeout = max(60, (int) config('fiscal_monitoring.job.timeout_seconds', 300));
        $this->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
    }

    public function handle(
        FiscalMonitoringRunService $runs,
        ManualConsultReadPolicy $manualConsultPolicy,
    ): void {
        try {
            $run = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->find($this->fiscalMonitoringRunId);
            if ($run !== null && ! $run->status->isTerminal()) {
                $manualConsultPolicy->assertRunMayExecute($run);
            }
            $runs->execute($this->fiscalMonitoringRunId);
        } catch (ManualConsultReadPolicyException $e) {
            $runs->blockBeforeExecution(
                $this->fiscalMonitoringRunId,
                $e->reasonCode,
                'Consulta bloqueada antes do transporte remoto.',
            );
        } catch (Throwable $e) {
            Log::warning('fiscal_monitoring.run_job_failed', [
                'run_id' => $this->fiscalMonitoringRunId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception !== null
            ? mb_substr($exception::class.': '.$exception->getMessage(), 0, 500)
            : null;

        app(FiscalMonitoringRunService::class)->failUnhandledJob(
            $this->fiscalMonitoringRunId,
            $message,
        );

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->find($this->fiscalMonitoringRunId);
        if ($run !== null) {
            app(PgdasdRbt12Service::class)->reconcileTerminalFailure($run);
        }
    }
}
