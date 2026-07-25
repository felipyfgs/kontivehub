<?php

namespace App\Jobs\Mailbox;

use App\Models\SerproEventosRun;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ProcessEventosLocalResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

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
}
