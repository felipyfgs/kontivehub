<?php

namespace App\Services\Communication\Flows;

use DomainException;

final class CommunicationFlowAvailability
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
            throw new DomainException('COMMUNICATION_FLOWS_DISABLED');
        }
    }

    public function assertRuntimeEnabled(): void
    {
        if (! $this->runtimeEnabled()) {
            throw new DomainException('COMMUNICATION_FLOWS_RUNTIME_DISABLED');
        }
    }
}
