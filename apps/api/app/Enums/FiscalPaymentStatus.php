<?php

namespace App\Enums;

/**
 * Pagamento separado de emissão de documento de arrecadação.
 * DARF/DAS emitido NÃO prova pagamento.
 */
enum FiscalPaymentStatus: string
{
    case Unknown = 'UNKNOWN';
    case Unpaid = 'UNPAID';
    case Paid = 'PAID';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Desconhecido',
            self::Unpaid => 'Não pago',
            self::Paid => 'Pago',
            self::Cancelled => 'Cancelado',
        };
    }
}
