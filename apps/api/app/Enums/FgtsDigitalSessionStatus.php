<?php

namespace App\Enums;

enum FgtsDigitalSessionStatus: string
{
    case Ready = 'READY';
    case Challenge = 'CHALLENGE';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
}
