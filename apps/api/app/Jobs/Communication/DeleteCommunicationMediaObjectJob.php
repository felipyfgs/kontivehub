<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Media\MediaDeletionService;
use App\Services\Communication\Media\MediaStore;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class DeleteCommunicationMediaObjectJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly string $objectId, public readonly ?int $intentId = null)
    {
        $this->onQueue('communication');
        $this->afterCommit();
    }

    public function handle(MediaStore $media, ?MediaDeletionService $deletions = null): void
    {
        $deletions ??= app(MediaDeletionService::class);
        try {
            if ($media->exists($this->objectId)) {
                $media->delete($this->objectId);
            }
            if ($this->intentId !== null) {
                $deletions->markDeleted($this->intentId);
            }
        } catch (\Throwable $error) {
            if ($this->intentId !== null) {
                $deletions->retry($this->intentId, $error);
            }
            throw $error;
        }
    }

    public function tags(): array
    {
        return ['communication', 'media-deletion', 'job:'.class_basename(self::class)];
    }

    public function uniqueId(): string
    {
        return $this->objectId;
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('job.failed', [
            'job' => class_basename(self::class),
            'message' => LogSanitizer::scrubString((string) ($e?->getMessage() ?? '')),
        ]);
    }
}
