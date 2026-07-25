<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Outbox\CommunicationOutboxDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchCommunicationOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $entryId)
    {
        $this->onQueue('communication');
    }

    public function handle(CommunicationOutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->entryId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['communication', 'outbox:'.$this->entryId];
    }
}
