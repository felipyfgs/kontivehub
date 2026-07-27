<?php

namespace App\Enums;

enum TaxProxyPowerSource: string
{
    case IntegraProcuracoes = 'INTEGRA_PROCURACOES';

    public function label(): string
    {
        return match ($this) {
            self::IntegraProcuracoes => 'API Integra-Procurações',
        };
    }
}
