<?php

namespace App\Enums\Work;

enum OperationalExportStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';
}
