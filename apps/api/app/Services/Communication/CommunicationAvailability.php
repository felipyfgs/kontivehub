<?php

namespace App\Services\Communication;

use App\Enums\Communication\CommunicationAvailabilityFailure;
use App\Enums\Communication\InboxStatus;
use App\Exceptions\CommunicationUnavailableException;
use App\Models\CommunicationInbox;

final class CommunicationAvailability
{
    public function assertGatewayAvailable(): void
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            throw new CommunicationUnavailableException(CommunicationAvailabilityFailure::GatewayDisabled);
        }
    }

    public function assertEnabled(CommunicationInbox $inbox, bool $requiresConnected = false): void
    {
        $this->assertGatewayAvailable();

        $inbox->loadMissing('tenant');
        if (! $inbox->tenant?->communication_enabled) {
            throw new CommunicationUnavailableException(CommunicationAvailabilityFailure::TenantDisabled);
        }
        if (! $inbox->is_enabled) {
            throw new CommunicationUnavailableException(CommunicationAvailabilityFailure::InboxDisabled);
        }
        if ($requiresConnected && $inbox->status !== InboxStatus::Connected) {
            throw new CommunicationUnavailableException(CommunicationAvailabilityFailure::InboxNotConnected);
        }
    }
}
