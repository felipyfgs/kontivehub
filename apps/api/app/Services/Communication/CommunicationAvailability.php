<?php

namespace App\Services\Communication;

use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use DomainException;

final class CommunicationAvailability
{
    public function assertGatewayAvailable(): void
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            throw new DomainException('COMMUNICATION_DISABLED');
        }
    }

    public function assertEnabled(CommunicationInbox $inbox, bool $requiresConnected = false): void
    {
        $this->assertGatewayAvailable();

        $inbox->loadMissing('office');
        if (! $inbox->office?->communication_enabled) {
            throw new DomainException('OFFICE_COMMUNICATION_DISABLED');
        }
        if (! $inbox->is_enabled) {
            throw new DomainException('INBOX_COMMUNICATION_DISABLED');
        }
        if ($requiresConnected && $inbox->status !== InboxStatus::Connected) {
            throw new DomainException('INBOX_NOT_CONNECTED');
        }
    }
}
