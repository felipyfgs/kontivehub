<?php

namespace App\Enums;

/**
 * Estado operacional de pagamento dos DAS do PA esperado (fail-closed).
 * Eixo ortogonal a {@see PgdasdDeclarationState} (entrega).
 */
enum PgdasdDasPaymentState: string
{
    case Paid = 'PAID';
    case Unpaid = 'UNPAID';
    case NoDas = 'NO_DAS';
    case Unverified = 'UNVERIFIED';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Em dia',
            self::Unpaid => 'Pendências',
            self::NoDas => 'Sem DAS',
            self::Unverified => 'Não verificado',
        };
    }
}
