<?php

namespace App\Jobs\Fiscal;

use App\Enums\FgtsDigitalRunStatus;
use App\Models\FgtsDigitalRun;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteFgtsDigitalRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $officeId,
        public readonly int $runId,
    ) {
        $this->onQueue((string) config('fgts_digital.queue', 'default'));
    }

    public function handle(FgtsDigitalPortalService $service): void
    {
        $run = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $this->officeId)
            ->whereKey($this->runId)
            ->first();
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        try {
            $service->executeRun($run);
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => FgtsDigitalRunStatus::Failed,
                'code' => 'FGTS_DIGITAL_JOB_FAILED',
                'result_sanitized' => ['message' => 'Execução FGTS Digital falhou de forma sanitizada.'],
                'finished_at' => now(),
            ])->save();
            Log::warning('fgts_digital.run_failed', [
                'office_id' => $this->officeId,
                'run_id' => $this->runId,
                'error_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['fgts-digital', 'office:'.$this->officeId, 'run:'.$this->runId];
    }
}
