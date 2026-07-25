<?php

namespace App\Enums;

/**
 * Estado de pagamento da guia lógica — NUNCA inferido por emissão/download.
 */
enum TaxGuidePaymentStatus: string
{
    case Unknown = 'UNKNOWN';
    case NotConfirmed = 'NOT_CONFIRMED';
    case Confirmed = 'CONFIRMED';
    case Partial = 'PARTIAL';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Pagamento desconhecido',
            self::NotConfirmed => 'Sem confirmação oficial',
            self::Confirmed => 'Pago (fonte oficial)',
            self::Partial => 'Pagamento parcial',
        };
    }

    public function isOfficiallyPaid(): bool
    {
        return $this === self::Confirmed || $this === self::Partial;
    }
}
