<?php

namespace App\Enums\Communication;

enum AvailabilityFailure: string
{
    case GatewayDisabled = 'COMMUNICATION_DISABLED';
    case TenantDisabled = 'TENANT_COMMUNICATION_DISABLED';
    case InboxDisabled = 'INBOX_COMMUNICATION_DISABLED';
    case InboxNotConnected = 'INBOX_NOT_CONNECTED';

    public function httpStatus(): int
    {
        return $this === self::InboxNotConnected ? 409 : 503;
    }
}
