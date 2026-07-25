<?php

namespace App\Enums;

enum MeiAutomationStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case WaitingUserAction = 'WAITING_USER_ACTION';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case Uncertain = 'UNCERTAIN';
    case SyncLost = 'SYNC_LOST';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::Uncertain, self::SyncLost], true);
    }

    public function shouldPoll(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
