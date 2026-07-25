<?php

namespace App\Enums;

enum FgtsDigitalRepresentationStatus: string
{
    case Active = 'ACTIVE';
    case Pending = 'PENDING';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
}
