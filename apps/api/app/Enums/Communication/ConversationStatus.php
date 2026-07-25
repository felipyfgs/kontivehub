<?php

namespace App\Enums\Communication;

enum ConversationStatus: string
{
    case Open = 'OPEN';
    case Pending = 'PENDING';
    case Resolved = 'RESOLVED';
    case Snoozed = 'SNOOZED';

    public function isActive(): bool
    {
        return $this !== self::Resolved;
    }
}
