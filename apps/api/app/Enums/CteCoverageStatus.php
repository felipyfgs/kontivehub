<?php

namespace App\Enums;

/**
 * Cobertura CT-e por cliente/período — projeção honesta, não promessa de inexistência.
 */
enum CteCoverageStatus: string
{
    case CapturedOriginal = 'CAPTURED_ORIGINAL';
    case CapturedAutXmlRedacted = 'CAPTURED_AUTXML_REDACTED';
    case PendingImport = 'PENDING_IMPORT';
    case HistoricalGap = 'HISTORICAL_GAP';
    case Blocked = 'BLOCKED';
    case NoActivity = 'NO_ACTIVITY';

    public function label(): string
    {
        return match ($this) {
            self::CapturedOriginal => 'Capturado (original)',
            self::CapturedAutXmlRedacted => 'Capturado (autXML redigido)',
            self::PendingImport => 'Pendente de importação',
            self::HistoricalGap => 'Lacuna histórica',
            self::Blocked => 'Bloqueado',
            self::NoActivity => 'Sem atividade observada',
        };
    }
}
