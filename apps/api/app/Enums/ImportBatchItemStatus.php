<?php

namespace App\Enums;

enum ImportBatchItemStatus: string
{
    case Pending = 'PENDING';
    case Imported = 'IMPORTED';
    case Duplicate = 'DUPLICATE';
    case Unmatched = 'UNMATCHED';
    case ClientMismatch = 'CLIENT_MISMATCH';
    case Invalid = 'INVALID';
    case Unsupported = 'UNSUPPORTED';
    case Quarantined = 'QUARANTINED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    public function isRetryable(): bool
    {
        return in_array($this, [self::Unmatched, self::Failed], true);
    }
}
