<?php

namespace App\Enums;

enum TaxGuideRiskLevel: string
{
    case Standard = 'STANDARD';
    case High = 'HIGH';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Padrão',
            self::High => 'Alto risco',
        };
    }

    public function requiresReinforcedConfirmation(): bool
    {
        return $this === self::High;
    }
}
