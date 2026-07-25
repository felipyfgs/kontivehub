<?php

namespace App\Enums;

/**
 * Situação da parcela projetada a partir da fonte oficial.
 * Nunca inventa "INADIMPLENTE" — atraso sem pagamento confirmado → ATTENTION/PENDING.
 */
enum TaxInstallmentParcelStatus: string
{
    case Open = 'OPEN';
    case AvailableToEmit = 'AVAILABLE_TO_EMIT';
    case Emitted = 'EMITTED';
    case Paid = 'PAID';
    case Attention = 'ATTENTION';
    case Pending = 'PENDING';
    case Unknown = 'UNKNOWN';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::AvailableToEmit => 'Disponível para emissão',
            self::Emitted => 'Documento emitido',
            self::Paid => 'Pagamento confirmado (fonte)',
            self::Attention => 'Atenção (vencida sem confirmação)',
            self::Pending => 'Pendente (fonte)',
            self::Unknown => 'Desconhecido',
            self::Cancelled => 'Cancelada',
        };
    }
}
