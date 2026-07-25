<?php

namespace App\Enums;

/** Mutabilidade declarada de uma operação fiscal no catálogo/adapter. */
enum FiscalMutability: string
{
    case ReadOnly = 'READ_ONLY';
    case Mutating = 'MUTATING';

    public function label(): string
    {
        return match ($this) {
            self::ReadOnly => 'Somente leitura',
            self::Mutating => 'Mutante',
        };
    }

    public function isMutating(): bool
    {
        return $this === self::Mutating;
    }
}
