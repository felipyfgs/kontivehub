<?php

namespace App\Enums;

/**
 * Ciclo de vida do pedido de canário DTE unitário.
 */
enum SerproDteCanaryRequestStatus: string
{
    case Draft = 'DRAFT';
    case TargetSet = 'TARGET_SET';
    case PartialApproved = 'PARTIAL_APPROVED';
    case FullyApproved = 'FULLY_APPROVED';
    case Dispatched = 'DISPATCHED';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Uncertain = 'UNCERTAIN';
    case Reconciled = 'RECONCILED';
    case Cancelled = 'CANCELLED';

    public function isTerminalAttempt(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Failed,
            self::Uncertain,
            self::Reconciled,
        ], true);
    }

    public function allowsDispatch(): bool
    {
        return $this === self::FullyApproved;
    }

    public function allowsReconciliation(): bool
    {
        return in_array($this, [self::Succeeded, self::Uncertain, self::Failed], true);
    }
}
