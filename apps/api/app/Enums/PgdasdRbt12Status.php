<?php

namespace App\Enums;

enum PgdasdRbt12Status: string
{
    case Pending = 'PENDING';
    case Parsed = 'PARSED';
    case NotFound = 'NOT_FOUND';
    case Ambiguous = 'AMBIGUOUS';
    case Failed = 'FAILED';
    case NoDas = 'NO_DAS';
}
