<?php

namespace App\Enums;

/**
 * Ciclo de vida imutável de uma versão de credencial SERPRO global.
 * PENDING → VERIFIED → ACTIVE → RETIRED | COMPROMISED
 */
enum SerproCredentialVersionStatus: string
{
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Active = 'ACTIVE';
    case Retired = 'RETIRED';
    case Compromised = 'COMPROMISED';

    public function isTerminal(): bool
    {
        return $this === self::Retired || $this === self::Compromised;
    }

    public function isUsableForAuth(): bool
    {
        return $this === self::Active || $this === self::Verified;
    }

    public function satisfiesRotationGate(): bool
    {
        return $this->isTerminal();
    }
}
