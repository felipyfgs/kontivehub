<?php

namespace App\Enums;

enum FiscalPendingStatus: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';
    case Dismissed = 'DISMISSED';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
