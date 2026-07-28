<?php

namespace App\Enums\Communication;

enum CommunicationFlowFailure: string
{
    case Disabled = 'COMMUNICATION_FLOWS_DISABLED';
    case RuntimeDisabled = 'COMMUNICATION_FLOWS_RUNTIME_DISABLED';
    case RunNotPaused = 'FLOW_RUN_NOT_PAUSED';
    case RunNotEligible = 'FLOW_RUN_NOT_ELIGIBLE';
    case RestartWithoutBinding = 'FLOW_RUN_RESTART_NO_BINDING';
    case RestartFlowPaused = 'FLOW_RUN_RESTART_FLOW_PAUSED';
    case RestartInvalid = 'FLOW_RUN_RESTART_INVALID';
    case RunTerminal = 'FLOW_RUN_TERMINAL';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Disabled,
            self::RuntimeDisabled => 403,
            default => 422,
        };
    }
}
