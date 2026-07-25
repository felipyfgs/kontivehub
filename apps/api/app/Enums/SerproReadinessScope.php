<?php

namespace App\Enums;

enum SerproReadinessScope: string
{
    case Global = 'GLOBAL';
    case Office = 'OFFICE';
    case Client = 'CLIENT';
    case Operation = 'OPERATION';
}
