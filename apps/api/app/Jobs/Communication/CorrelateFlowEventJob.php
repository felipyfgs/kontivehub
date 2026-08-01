<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Flows\FlowCorrelator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CorrelateFlowEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $eventKey,
    ) {
        $this->onQueue('communication');
    }

    public function handle(FlowCorrelator $correlator): void
    {
        $correlator->correlateMessage(
            $this->tenantId,
            $this->conversationId,
            $this->messageId,
            $this->eventKey,
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'communication',
            'flow-correlate',
            'conversation:'.$this->conversationId,
            'message:'.$this->messageId,
        ];
    }
}
