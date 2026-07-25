<?php

namespace App\Enums;

enum TaxProxyPowerStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Insufficient = 'INSUFFICIENT';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
