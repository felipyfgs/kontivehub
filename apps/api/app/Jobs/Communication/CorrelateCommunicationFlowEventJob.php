<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Flows\CommunicationFlowCorrelator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CorrelateCommunicationFlowEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $officeId,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $eventKey,
    ) {
        $this->onQueue('communication');
    }

    public function handle(CommunicationFlowCorrelator $correlator): void
    {
        $correlator->correlateMessage(
            $this->officeId,
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
