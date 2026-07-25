<?php

namespace App\Enums;

enum SerproUsageReservationStatus: string
{
    case Reserved = 'RESERVED';
    case Finalized = 'FINALIZED';
    case Released = 'RELEASED';
    case Blocked = 'BLOCKED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Finalized, self::Released, self::Blocked => true,
            self::Reserved => false,
        };
    }
}
