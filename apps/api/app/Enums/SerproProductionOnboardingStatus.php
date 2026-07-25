<?php

namespace App\Enums;

enum SerproProductionOnboardingStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case ActiveSyncPending = 'ACTIVE_SYNC_PENDING';
    case Active = 'ACTIVE';
    case ActionRequired = 'ACTION_REQUIRED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Active, self::ActionRequired, self::Failed], true);
    }
}
