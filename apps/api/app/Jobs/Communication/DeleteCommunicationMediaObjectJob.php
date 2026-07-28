<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Media\CommunicationMediaStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeleteCommunicationMediaObjectJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly string $objectId)
    {
        $this->afterCommit();
    }

    public function handle(CommunicationMediaStore $media): void
    {
        if ($media->exists($this->objectId)) {
            $media->delete($this->objectId);
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
