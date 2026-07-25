<?php

namespace App\Services\Serpro;

use App\Models\SerproAsyncJobRun;
use App\Support\LogSanitizer;

/**
 * Persistência de run/cursor/erro sanitizado para jobs SERPRO/fiscal.
 */
final class SerproAsyncJobRunStore
{
    /**
     * @param  array<string, mixed>  $progress
     */
    public function start(
        string $jobType,
        int $officeId,
        ?int $clientId,
        ?string $correlationId,
        bool $flagCheckedAtDispatch,
        ?string $environment = null,
        ?string $cursor = null,
        array $progress = [],
    ): SerproAsyncJobRun {
        return SerproAsyncJobRun::query()->create([
            'job_type' => $jobType,
            'office_id' => $officeId,
            'client_id' => $clientId,
            'environment' => $environment,
            'status' => SerproAsyncJobRun::STATUS_RUNNING,
            'correlation_id' => $correlationId,
            'attempt' => 1,
            'cursor' => $cursor,
            'pages_done' => 0,
            'flag_checked_at_dispatch' => $flagCheckedAtDispatch,
            'flag_checked_at_handle' => false,
            'progress' => $progress === [] ? null : $progress,
            'started_at' => now(),
        ]);
    }

    public function markFlagAtHandle(SerproAsyncJobRun $run): void
    {
        $run->forceFill(['flag_checked_at_handle' => true])->save();
    }

    public function advanceCursor(SerproAsyncJobRun $run, ?string $cursor, int $pagesDone = 0): void
    {
        $run->forceFill([
            'cursor' => $cursor,
            'pages_done' => $pagesDone,
        ])->save();
    }

    public function succeed(SerproAsyncJobRun $run, ?array $progress = null): void
    {
        $run->forceFill([
            'status' => SerproAsyncJobRun::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'error_code' => null,
            'error_message' => null,
            'progress' => $progress ?? $run->progress,
        ])->save();
    }

    public function fail(
        SerproAsyncJobRun $run,
        string $code,
        string $message,
        string $status = SerproAsyncJobRun::STATUS_FAILED,
        ?\DateTimeInterface $nextRetryAt = null,
    ): void {
        $run->forceFill([
            'status' => $status,
            'error_code' => mb_substr($code, 0, 80),
            'error_message' => mb_substr(LogSanitizer::scrubString($message), 0, 500),
            'finished_at' => now(),
            'next_retry_at' => $nextRetryAt,
        ])->save();
    }

    public function bumpAttempt(SerproAsyncJobRun $run): void
    {
        $run->forceFill([
            'attempt' => (int) $run->attempt + 1,
            'status' => SerproAsyncJobRun::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
        ])->save();
    }
}
