<?php

namespace App\Jobs\MeiAutomation;

use App\Enums\MeiAutomationStatus;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Services\MeiAutomation\MeiAutomationSyncService;
use App\Services\MeiAutomation\MeiDasMutationReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncMeiAutomationAttemptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $attemptId,
    ) {}

    public function handle(
        MeiAutomationAttemptRepository $attempts,
        MeiAutomationSyncService $sync,
        MeiDasMutationReconciler $mutations,
    ): void {
        $attempt = $attempts->findForTenant($this->tenantId, $this->attemptId);
        $attempt = $sync->synchronize($attempt);
        $mutations->reconcile($attempt);
        $status = $attempt->status;
        if ($status instanceof MeiAutomationStatus && $status->shouldPoll()) {
            $sync->schedule($attempt);
        }
    }

    public function tags(): array
    {
        return ['job:'.class_basename(static::class)];
    }

    public function failed(?\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning('job.failed', [
            'job' => class_basename(static::class),
            'message' => \App\Support\LogSanitizer::scrubString((string) ($e?->getMessage() ?? '')),
        ]);
    }
}
