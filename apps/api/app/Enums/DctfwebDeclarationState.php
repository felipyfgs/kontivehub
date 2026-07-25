<?php

namespace App\Enums;

/**
 * Estado operacional da declaração DCTFWeb para o PA esperado (fail-closed).
 */
enum DctfwebDeclarationState: string
{
    case Current = 'CURRENT';
    case NoMovementValid = 'NO_MOVEMENT_VALID';
    case DueWithinDeadline = 'DUE_WITHIN_DEADLINE';
    case OverdueNotFound = 'OVERDUE_NOT_FOUND';
    case Unverified = 'UNVERIFIED';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Em dia',
            self::NoMovementValid => 'Sem movimento',
            self::DueWithinDeadline => 'No prazo',
            self::OverdueNotFound => 'Sem declaração',
            self::Unverified => 'Não verificado',
        };
    }
}
