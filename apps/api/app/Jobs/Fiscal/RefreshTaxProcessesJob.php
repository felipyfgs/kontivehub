<?php

namespace App\Jobs\Fiscal;

use App\Models\Client;
use App\Models\Office;
use App\Models\SerproAsyncJobRun;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicy;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicyException;
use App\Services\Integra\TaxProcesses\TaxProcessProjectionService;
use App\Services\Serpro\SerproAsyncJobRunStore;
use App\Services\Serpro\SerproJobFlagGuard;
use App\Services\Serpro\SerproMetricsExporter;
use App\Support\FiscalDataModel\PrivilegedOfficeContext;
use App\Support\LogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Refresh Processos fiscais — fila Horizon `fiscal`, flags no dispatch e no handle,
 * run/cursor durável, retry com backoff real.
 */
final class RefreshTaxProcessesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff;

    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly ?string $correlationId = null,
        public readonly bool $flagCheckedAtDispatch = false,
        public readonly ?int $asyncRunId = null,
        public readonly ?string $manualActionId = null,
        public readonly ?int $actorUserId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
        $this->tries = max(1, (int) config('serpro.jobs.tries', 3));
        $this->timeout = max(60, (int) config('serpro.jobs.timeout_seconds', 300));
        $backoff = config('serpro.jobs.backoff', [30, 120, 300]);
        $this->backoff = is_array($backoff) ? array_values(array_map('intval', $backoff)) : [30, 120, 300];
    }

    public static function dispatchIfAllowed(
        int $officeId,
        int $clientId,
        ?string $correlationId = null,
        ?string $manualActionId = null,
        ?int $actorUserId = null,
    ): ?self {
        $guard = app(SerproJobFlagGuard::class);
        $check = $guard->assertAllowed('RefreshTaxProcessesJob', $officeId);
        if (! $check['allowed']) {
            Log::info('serpro.job.dispatch_blocked', [
                'job' => 'RefreshTaxProcessesJob',
                'code' => $check['code'],
                'office_id' => $officeId,
            ]);

            return null;
        }

        $store = app(SerproAsyncJobRunStore::class);
        $run = $store->start(
            jobType: 'RefreshTaxProcessesJob',
            officeId: $officeId,
            clientId: $clientId,
            correlationId: $correlationId,
            flagCheckedAtDispatch: true,
        );

        $job = new self(
            $officeId,
            $clientId,
            $correlationId,
            true,
            $run->id,
            $manualActionId,
            $actorUserId,
        );
        dispatch($job);

        return $job;
    }

    public function handle(
        TaxProcessProjectionService $service,
        SerproJobFlagGuard $flags,
        SerproAsyncJobRunStore $runs,
        SerproMetricsExporter $metrics,
        ManualConsultReadPolicy $manualConsultPolicy,
    ): void {
        $run = $this->asyncRunId !== null
            ? SerproAsyncJobRun::query()->find($this->asyncRunId)
            : null;

        if ($run === null) {
            $run = $runs->start(
                jobType: 'RefreshTaxProcessesJob',
                officeId: $this->officeId,
                clientId: $this->clientId,
                correlationId: $this->correlationId,
                flagCheckedAtDispatch: $this->flagCheckedAtDispatch,
            );
        } elseif ($run->status !== SerproAsyncJobRun::STATUS_RUNNING) {
            $runs->bumpAttempt($run);
        }

        $check = $flags->assertAllowed('RefreshTaxProcessesJob', $this->officeId);
        $runs->markFlagAtHandle($run);
        if (! $check['allowed']) {
            $runs->fail($run, (string) $check['code'], (string) $check['message'], SerproAsyncJobRun::STATUS_BLOCKED);

            return;
        }

        if ($this->manualActionId !== null) {
            $office = Office::query()->find($this->officeId);
            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $this->officeId)
                ->whereKey($this->clientId)
                ->first();
            if ($office === null || $client === null) {
                $runs->fail(
                    $run,
                    'MANUAL_TENANT_CONTEXT_MISSING',
                    'Contexto da consulta indisponível.',
                    SerproAsyncJobRun::STATUS_BLOCKED,
                );

                return;
            }

            try {
                $manualConsultPolicy->assertAsyncJobMayExecute(
                    $office,
                    $client,
                    $this->manualActionId,
                    $this->actorUserId,
                    $run,
                );
            } catch (ManualConsultReadPolicyException $e) {
                $runs->fail(
                    $run,
                    $e->reasonCode,
                    'Consulta bloqueada antes do transporte remoto.',
                    SerproAsyncJobRun::STATUS_BLOCKED,
                );

                return;
            }
        }

        PrivilegedOfficeContext::enter('job:RefreshTaxProcessesJob');
        try {
            $office = Office::query()->findOrFail($this->officeId);
            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $this->officeId)
                ->whereKey($this->clientId)
                ->firstOrFail();

            $result = $service->refresh($office, $client, $this->correlationId);

            if (! ($result['success'] ?? false)) {
                $code = (string) ($result['error_code'] ?? 'REFRESH_FAILED');
                $message = (string) ($result['error_message'] ?? 'Falha no refresh de processos.');

                if ($this->isPermanent($code)) {
                    $runs->fail($run, $code, $message);
                    $this->fail(new RuntimeException($code.': '.$message));

                    return;
                }

                if ($this->isRateLimited($code)) {
                    $runs->fail($run, $code, $message, SerproAsyncJobRun::STATUS_RATE_LIMITED);

                    return;
                }

                $runs->fail($run, $code, $message);
                throw new RuntimeException($code.': '.$message);
            }

            $runs->advanceCursor($run, cursor: null, pagesDone: 1);
            $runs->succeed($run, [
                'count' => $result['count'] ?? 0,
                'simulated' => $result['simulated'] ?? false,
            ]);
        } catch (Throwable $e) {
            $metrics->recordRetry('tax_processes');
            Log::warning('serpro.job.tax_processes_failed', [
                'office_id' => $this->officeId,
                'client_id' => $this->clientId,
                'error' => LogSanitizer::scrubString(mb_substr($e->getMessage(), 0, 200)),
                'attempt' => $this->attempts(),
            ]);
            if ($run !== null) {
                $runs->fail($run, 'JOB_EXCEPTION', $e->getMessage());
            }
            throw $e;
        } finally {
            PrivilegedOfficeContext::leave();
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return $this->backoff;
    }

    public function failed(?Throwable $e): void
    {
        Log::error('serpro.job.tax_processes_exhausted', [
            'office_id' => $this->officeId,
            'client_id' => $this->clientId,
            'error' => $e !== null ? LogSanitizer::scrubString(mb_substr($e->getMessage(), 0, 200)) : null,
        ]);
    }

    private function isPermanent(string $code): bool
    {
        return in_array($code, [
            'CAPABILITY_DISABLED',
            'CONTRACT_UNAVAILABLE',
            'RESPONSE_LAYOUT_INVALID',
        ], true);
    }

    private function isRateLimited(string $code): bool
    {
        return str_contains($code, 'RATE_LIMIT') || $code === '429';
    }
}
