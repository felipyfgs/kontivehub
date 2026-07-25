<?php

namespace App\Enums;

enum OutboundMonthlyReadinessStatus: string
{
    case CompleteKnown = 'COMPLETE_KNOWN';
    case PartialConfirmed = 'PARTIAL_CONFIRMED';
    case NotReady = 'NOT_READY';

    public function label(): string
    {
        return match ($this) {
            self::CompleteKnown => 'Completo (documentos conhecidos)',
            self::PartialConfirmed => 'Parcial confirmado',
            self::NotReady => 'Não pronto',
        };
    }
}
