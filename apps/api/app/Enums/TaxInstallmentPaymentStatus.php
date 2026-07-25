<?php

namespace App\Enums;

/** Estado de pagamento — só CONFIRMED com evidência oficial. */
enum TaxInstallmentPaymentStatus: string
{
    case None = 'NONE';
    case Confirmed = 'CONFIRMED';
    case Unknown = 'UNKNOWN';
    case NotReported = 'NOT_REPORTED';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem confirmação',
            self::Confirmed => 'Confirmado pela fonte',
            self::Unknown => 'Incerto',
            self::NotReported => 'Não informado',
        };
    }
}
