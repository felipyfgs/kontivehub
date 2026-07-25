<?php

namespace App\Enums;

/**
 * Proveniência verificável de runs/evidências/snapshots fiscais.
 * Definida pelo driver — nunca pelo payload ou frontend.
 */
enum FiscalSourceProvenance: string
{
    case SerproTrial = 'SERPRO_TRIAL';
    case SerproReal = 'SERPRO_REAL';
    case Fixture = 'FIXTURE';
    case ReceitaPortal = 'RECEITA_PORTAL';
    case Unverified = 'UNVERIFIED';

    public function isVerifiableCurrent(): bool
    {
        return match ($this) {
            self::SerproReal, self::ReceitaPortal => true,
            self::SerproTrial, self::Fixture, self::Unverified => false,
        };
    }

    public function isOfficialFiscalState(): bool
    {
        return in_array($this, [self::SerproReal, self::ReceitaPortal], true);
    }
}
