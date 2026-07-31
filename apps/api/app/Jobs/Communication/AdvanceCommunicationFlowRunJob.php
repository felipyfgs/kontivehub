<?php

namespace App\Jobs\Communication;

use App\Services\Communication\Flows\FlowExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class AdvanceCommunicationFlowRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('communication');
    }

    public function handle(FlowExecutor $executor): void
    {
        $executor->advance($this->runId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['communication', 'flow-advance', 'run:'.$this->runId];
    }
}
