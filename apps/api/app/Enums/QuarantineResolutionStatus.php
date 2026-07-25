<?php

namespace App\Enums;

enum QuarantineResolutionStatus: string
{
    case Open = 'OPEN';
    case Resolved = 'RESOLVED';
    case Dismissed = 'DISMISSED';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
