<?php

namespace App\Enums;

enum SerproReconciliationStatus: string
{
    case Open = 'OPEN';
    case Matched = 'MATCHED';
    case Divergent = 'DIVERGENT';
    case Adjusted = 'ADJUSTED';
    case Closed = 'CLOSED';
}
