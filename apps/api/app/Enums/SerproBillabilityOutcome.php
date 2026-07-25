<?php

namespace App\Enums;

/**
 * Resultado da classificação de bilhetagem (pré e pós transporte).
 */
enum SerproBillabilityOutcome: string
{
    case NonBillable = 'NON_BILLABLE';
    case Billable = 'BILLABLE';
    case PossiblyBillable = 'POSSIBLY_BILLABLE';
    case UnknownBlocked = 'UNKNOWN_BLOCKED';

    public function isBillableAttempt(): bool
    {
        return $this === self::Billable || $this === self::PossiblyBillable;
    }

    public function blocksProductiveEgress(): bool
    {
        return $this === self::UnknownBlocked;
    }
}
