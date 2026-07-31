<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\CommunicationFlowFailure;
use App\Exceptions\CommunicationFlowException;

final class FlowAvailability
{
    public function enabled(): bool
    {
        return (bool) config('communication.flows.enabled', false);
    }

    public function runtimeEnabled(): bool
    {
        return $this->enabled() && (bool) config('communication.flows.runtime_enabled', false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new CommunicationFlowException(CommunicationFlowFailure::Disabled);
        }
    }

    public function assertRuntimeEnabled(): void
    {
        if (! $this->runtimeEnabled()) {
            throw new CommunicationFlowException(CommunicationFlowFailure::RuntimeDisabled);
        }
    }
}
