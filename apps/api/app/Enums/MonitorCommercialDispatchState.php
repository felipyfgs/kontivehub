<?php

namespace App\Enums;

/**
 * Estado de despacho da unidade comercial (ledger separado do técnico).
 */
enum MonitorCommercialDispatchState: string
{
    case Pending = 'pending';
    case Dispatched = 'dispatched';
    case Completed = 'completed';
    case BlockedQuota = 'blocked_quota';
    case BlockedProxy = 'blocked_proxy';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function consumesQuota(): bool
    {
        return in_array($this, [self::Dispatched, self::Completed], true);
    }
}
