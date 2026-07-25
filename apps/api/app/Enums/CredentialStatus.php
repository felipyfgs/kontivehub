<?php

namespace App\Enums;

enum CredentialStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
