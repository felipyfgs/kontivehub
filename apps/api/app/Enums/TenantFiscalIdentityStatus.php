<?php

namespace App\Enums;

enum TenantFiscalIdentityStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
