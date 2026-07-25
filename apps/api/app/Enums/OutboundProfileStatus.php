<?php

namespace App\Enums;

enum OutboundProfileStatus: string
{
    case Draft = 'DRAFT';
    case SeedReady = 'SEED_READY';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Blocked = 'BLOCKED';
    case KillSwitched = 'KILL_SWITCHED';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
