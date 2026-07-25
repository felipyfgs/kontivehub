<?php

namespace App\Enums;

enum OutboundCaptureRunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
    case Blocked = 'BLOCKED';
}
