<?php

namespace App\Jobs\Mailbox;

use App\Models\SerproEventosRun;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use App\Support\LogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessEventosLocalResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(public readonly int $eventosRunId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('eventos-local:'.$this->eventosRunId))->expireAfter(600)];
    }

    public function handle(MailboxEventosResultProcessor $processor): void
    {
        $run = SerproEventosRun::query()->withoutGlobalScopes()->find($this->eventosRunId);
        if ($run === null || ! $run->isOneShotConsumed()
            || $run->local_processing_status === MailboxEventosResultProcessor::LOCAL_SUCCEEDED) {
            return;
        }
        $processor->process($run); // exclusivamente local: não injeta executor HTTP.
    }

    public function tags(): array
    {
        return ['job:'.class_basename(self::class)];
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('job.failed', [
            'job' => class_basename(self::class),
            'message' => LogSanitizer::scrubString((string) ($e?->getMessage() ?? '')),
        ]);
    }
}
