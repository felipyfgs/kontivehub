<?php

namespace App\Jobs\Serpro;

use App\Models\SerproEventosRun;
use App\Services\Integra\Eventos\EventosAtualizacaoFlowService;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use App\Services\Serpro\SerproJobFlagGuard;
use App\Services\Serpro\SerproMetricsExporter;
use App\Support\LogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Poll/obtain de Eventos de Atualização respeitando TempoEsperaMedioEmMs da run.
 * Em 429: não requeue até janela permitir (status RATE_LIMITED).
 */
final class PollEventosAtualizacaoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff;

    public function __construct(
        public readonly int $eventosRunId,
    ) {
        $this->onQueue((string) config('serpro.eventos.queue', config('serpro.queues.fiscal', 'fiscal')));
        $this->tries = max(1, (int) config('serpro.jobs.tries', 3));
        $this->timeout = max(60, (int) config('serpro.jobs.timeout_seconds', 300));
        $backoff = config('serpro.jobs.backoff', [30, 120, 300]);
        $this->backoff = is_array($backoff) ? array_values(array_map('intval', $backoff)) : [30, 120, 300];
    }

    public function handle(
        EventosAtualizacaoFlowService $flow,
        SerproJobFlagGuard $flags,
        SerproMetricsExporter $metrics,
    ): void {
        $run = SerproEventosRun::query()->find($this->eventosRunId);
        if ($run === null) {
            return;
        }

        if ($run->status === SerproEventosRun::STATUS_SUCCEEDED
            && $run->local_processing_status === MailboxEventosResultProcessor::LOCAL_SUCCEEDED) {
            return;
        }

        if ($run->status === SerproEventosRun::STATUS_RATE_LIMITED) {
            // Sem retry até janela — job encerra sem throw
            return;
        }

        $check = $flags->assertAllowed('PollEventosAtualizacaoJob', (int) $run->office_id);
        if (! $check['allowed']) {
            $run->forceFill([
                'status' => SerproEventosRun::STATUS_BLOCKED,
                'error_code' => $check['code'],
                'error_message' => mb_substr((string) $check['message'], 0, 500),
            ])->save();

            return;
        }

        $wait = $flow->secondsUntilObtain($run);
        if ($wait > 0) {
            // Reagenda com delay oficial (não hardcoded TTL de protocolo)
            self::dispatch($this->eventosRunId)->delay(now()->addSeconds($wait));

            return;
        }

        $updated = $flow->obtain($run);

        if ($updated->phase === SerproEventosRun::PHASE_WAITING) {
            $again = $flow->secondsUntilObtain($updated);
            self::dispatch($this->eventosRunId)->delay(now()->addSeconds(max(1, $again)));

            return;
        }

        if ($updated->status === SerproEventosRun::STATUS_RATE_LIMITED) {
            $metrics->recordHttp(429, 0, 'serpro_eventos');

            return;
        }

        if ($updated->status === SerproEventosRun::STATUS_FAILED) {
            // Erro permanente de negócio — sem retry infinito
            return;
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
        Log::error('serpro.job.eventos_poll_exhausted', [
            'eventos_run_id' => $this->eventosRunId,
            'error' => $e !== null ? LogSanitizer::scrubString(mb_substr($e->getMessage(), 0, 200)) : null,
        ]);
    }
}
