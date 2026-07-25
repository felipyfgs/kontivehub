<?php

namespace App\Enums;

enum OutboundDeadlineStatus: string
{
    case Open = 'OPEN';
    case OnTrack = 'ON_TRACK';
    case AtRisk = 'AT_RISK';
    case Met = 'MET';
    case Missed = 'MISSED';
    case Cancelled = 'CANCELLED';
}
