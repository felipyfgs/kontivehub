<?php

namespace App\Enums;

enum SerproExternalGateStatus: string
{
    case Open = 'OPEN';
    case Submitted = 'SUBMITTED';
    case Answered = 'ANSWERED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Waived = 'WAIVED';

    public function blocksProduction(): bool
    {
        // WAIVED não desbloqueia Production (sem waiver silencioso).
        return match ($this) {
            self::Accepted => false,
            default => true,
        };
    }
}
