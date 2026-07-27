<?php

namespace App\Enums;

enum AdnDocumentType: string
{
    case Nfse = 'NFSE';
    case Event = 'EVENTO';
    case Nfe = 'NFE';
    case NfeEvent = 'NFE_EVENTO';
    case Cte = 'CTE';
    case Unknown = 'UNKNOWN';
}
