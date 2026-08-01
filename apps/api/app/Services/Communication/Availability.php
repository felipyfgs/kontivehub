<?php

namespace App\Services\Communication;

use App\Enums\Communication\AvailabilityFailure;
use App\Enums\Communication\InboxStatus;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationInbox;

final class Availability
{
    public function assertGatewayAvailable(): void
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            throw new CommunicationUnavailableException(AvailabilityFailure::GatewayDisabled);
        }
    }

    public function assertEnabled(CommunicationInbox $inbox, bool $requiresConnected = false): void
    {
        $this->assertGatewayAvailable();

        $inbox->loadMissing('tenant');
        if (! $inbox->tenant?->communication_enabled
            || ! $inbox->tenant->is_active
            || ! $inbox->tenant->isOperational()) {
            throw new CommunicationUnavailableException(AvailabilityFailure::TenantDisabled);
        }
        if (! $inbox->is_enabled) {
            throw new CommunicationUnavailableException(AvailabilityFailure::InboxDisabled);
        }
        if ($requiresConnected && $inbox->status !== InboxStatus::Connected) {
            throw new CommunicationUnavailableException(AvailabilityFailure::InboxNotConnected);
        }
    }
}
