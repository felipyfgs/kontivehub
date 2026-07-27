<?php

namespace App\Enums;

enum SerproReadinessScope: string
{
    case Global = 'GLOBAL';
    case Tenant = 'TENANT';
    case Client = 'CLIENT';
    case Operation = 'OPERATION';
}
