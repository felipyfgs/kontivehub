<?php

declare(strict_types=1);

namespace App\Enums;

enum EsocialBxFailureClass: string
{
    case Retryable = 'RETRYABLE';
    case Permanent = 'PERMANENT';
    case Blocked = 'BLOCKED';
}
