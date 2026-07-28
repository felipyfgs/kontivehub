<?php

namespace App\Actions\Communication;

use App\Models\CommunicationFlowRun;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Support\CurrentTenant;

final class ControlCommunicationFlowRunAction
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationFlowRunControlService $controls,
    ) {}

    public function pause(CommunicationFlowRun $run): CommunicationFlowRun
    {
        return $this->controls->pause($run, $this->currentTenant->realMembership());
    }

    public function resume(CommunicationFlowRun $run): CommunicationFlowRun
    {
        return $this->controls->resume($run, $this->currentTenant->realMembership());
    }

    public function handoff(CommunicationFlowRun $run): CommunicationFlowRun
    {
        return $this->controls->handoff($run, $this->currentTenant->realMembership());
    }

    public function stop(CommunicationFlowRun $run): CommunicationFlowRun
    {
        return $this->controls->stop($run, $this->currentTenant->realMembership());
    }

    public function restart(CommunicationFlowRun $run): CommunicationFlowRun
    {
        return $this->controls->restart($run, $this->currentTenant->realMembership());
    }
}
