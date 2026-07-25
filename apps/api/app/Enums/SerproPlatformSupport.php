<?php

namespace App\Enums;

/**
 * Estado de suporte da plataforma para uma operation_key.
 * INVENTORIED ≠ executável; PRODUCTION_VALIDATED exige smoke real.
 */
enum SerproPlatformSupport: string
{
    case Inventoried = 'INVENTORIED';
    case Simulated = 'SIMULATED';
    case Implemented = 'IMPLEMENTED';
    case ProductionValidated = 'PRODUCTION_VALIDATED';

    public function isExecutable(): bool
    {
        return match ($this) {
            self::Simulated, self::Implemented, self::ProductionValidated => true,
            self::Inventoried => false,
        };
    }
}
