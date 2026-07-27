<?php

namespace App\Enums;

enum FiscalModuleControlScope: string
{
    case Global = 'GLOBAL';
    case Tenant = 'TENANT';
}
