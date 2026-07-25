<?php

namespace App\Enums;

/** Estado do vínculo escritório–cliente–categoria fiscal. */
enum FiscalLinkStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Pending = 'PENDING';

    public function isSchedulable(): bool
    {
        return $this === self::Active;
    }
}
