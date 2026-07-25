<?php

namespace App\Enums;

enum MonitoringFreshnessState: string
{
    case Fresh = 'FRESH';
    case Stale = 'STALE';
    case Unknown = 'UNKNOWN';
}
