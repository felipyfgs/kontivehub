<?php

namespace App\Enums;

/**
 * Resumo do estado oficial das operações da superfície (catálogo SERPRO).
 */
enum MonitoringOfficialStateSummary: string
{
    case Production = 'PRODUCTION';
    case Mixed = 'MIXED';
    case Prospection = 'PROSPECTION';
    case NotApplicable = 'N_A';

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Produtivo',
            self::Mixed => 'Misto',
            self::Prospection => 'Prospecção',
            self::NotApplicable => 'Não aplicável',
        };
    }
}
