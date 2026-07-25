<?php

namespace App\Enums;

enum MailboxEventItemClassification: string
{
    case NoEvent = 'SEM_EVENTO';
    case AccessDenied = 'ACESSO_NEGADO';
    case EventDate = 'DATA_EVENTO';
    case Malformed = 'MALFORMADO';
    case Unmatched = 'NAO_ASSOCIADO';
}
