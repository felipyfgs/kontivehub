<?php

namespace App\Enums;

enum OutboundSeriesStatus: string
{
    case SeedReady = 'SEED_READY';
    case Idle = 'IDLE';
    case Running = 'RUNNING';
    case Blocked = 'BLOCKED';
    case ExhaustedVisible = 'EXHAUSTED_VISIBLE';
    case FiscalIncident = 'FISCAL_INCIDENT';
    case Closed = 'CLOSED';
}
