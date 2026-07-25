<?php

namespace App\Enums;

/**
 * Aplicabilidade de obrigação declaratória por contribuinte/regime/regra versionada.
 * MUST NOT presumir pendência quando UNKNOWN.
 */
enum TaxObligationApplicability: string
{
    case Applicable = 'APPLICABLE';
    case NotApplicable = 'NOT_APPLICABLE';
    case Unknown = 'UNKNOWN';
    case Unsupported = 'UNSUPPORTED';

    public function label(): string
    {
        return match ($this) {
            self::Applicable => 'Aplicável',
            self::NotApplicable => 'Não aplicável',
            self::Unknown => 'Aplicabilidade desconhecida',
            self::Unsupported => 'Não suportada',
        };
    }

    /** Permite calcular pendência de entrega (só se aplicável). */
    public function allowsDeliveryPending(): bool
    {
        return $this === self::Applicable;
    }

    public function toFiscalSituation(): FiscalSituation
    {
        return match ($this) {
            self::Applicable => FiscalSituation::Unknown, // ainda sem evidência de entrega
            self::NotApplicable => FiscalSituation::NotApplicable,
            self::Unknown => FiscalSituation::Unknown,
            self::Unsupported => FiscalSituation::Unsupported,
        };
    }
}
