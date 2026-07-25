<?php

namespace App\Enums;

enum SerproContractStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Blocked = 'BLOCKED';
    case Superseded = 'SUPERSEDED';
    case Inactive = 'INACTIVE';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
