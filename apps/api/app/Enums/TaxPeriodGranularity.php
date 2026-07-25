<?php

namespace App\Enums;

enum TaxPeriodGranularity: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Annual = 'ANNUAL';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Annual => 'Anual',
        };
    }
}
