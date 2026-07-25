<?php

namespace App\Enums;

enum TaxProxyPowerSource: string
{
    case IntegraProcuracoes = 'INTEGRA_PROCURACOES';
    case ManualOfficialEvidence = 'MANUAL_OFFICIAL_EVIDENCE';
    case Import = 'IMPORT';

    public function label(): string
    {
        return match ($this) {
            self::IntegraProcuracoes => 'API Integra-Procurações',
            self::ManualOfficialEvidence => 'Evidência oficial manual',
            self::Import => 'Importação',
        };
    }
}
