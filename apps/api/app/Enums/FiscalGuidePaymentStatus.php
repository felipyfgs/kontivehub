<?php

namespace App\Enums;

/**
 * Pagamento é independente de emissão (spec guias / DAS).
 */
enum FiscalGuidePaymentStatus: string
{
    case Unknown = 'UNKNOWN';
    case Unpaid = 'UNPAID';
    case Paid = 'PAID';
    case Partial = 'PARTIAL';
    case NotApplicable = 'NOT_APPLICABLE';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Pagamento desconhecido',
            self::Unpaid => 'Não pago',
            self::Paid => 'Pago',
            self::Partial => 'Parcialmente pago',
            self::NotApplicable => 'Não aplicável',
        };
    }
}
