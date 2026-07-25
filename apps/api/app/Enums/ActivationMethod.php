<?php

namespace App\Enums;

enum ActivationMethod: string
{
    case ManualLink = 'MANUAL_LINK';
    case TemporaryPassword = 'TEMPORARY_PASSWORD';
}
