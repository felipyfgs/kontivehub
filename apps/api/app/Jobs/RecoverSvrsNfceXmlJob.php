<?php

namespace App\Jobs;

use App\Services\Outbound\OutboundXmlRecoveryOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job de recuperação SVRS — payload somente com id interno da recovery.
 * Unique por request: evita storm de workers no mesmo recovery.
 */
class RecoverSvrsNfceXmlJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $retrievalRequestId,
    ) {
        $this->onQueue((string) config('sefaz.svrs_nfce_xml.queue', 'capture-outbound-ma'));
        $this->timeout = (int) config('sefaz.svrs_nfce_xml.job_timeout_seconds', 120);
    }

    public function uniqueId(): string
    {
        return 'svrs-nfce-recovery-'.$this->retrievalRequestId;
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('sefaz.svrs_nfce_xml.lock_ttl_seconds', 180));
    }

    public function handle(OutboundXmlRecoveryOrchestrator $orchestrator): void
    {
        // Kill/flag tratados no orchestrator (RETRY_SCHEDULED, não no-op cego).
        try {
            $orchestrator->runAttempt($this->retrievalRequestId);
        } catch (Throwable $e) {
            Log::warning('svrs_nfce.job.failed', [
                'retrieval_request_id' => $this->retrievalRequestId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(): array
    {
        return ['svrs-nfce', 'retrieval:'.$this->retrievalRequestId];
    }
}
