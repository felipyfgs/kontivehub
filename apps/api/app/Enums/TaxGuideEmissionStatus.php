<?php

namespace App\Enums;

/**
 * Estado de emissão de uma versão de guia (tax_guide_versions).
 * Independente do payment_status em tax_guides.
 */
enum TaxGuideEmissionStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case UnknownResult = 'UNKNOWN_RESULT';
    case Reconciling = 'RECONCILING';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
    case Superseded = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Sent => 'Enviada',
            self::Confirmed => 'Confirmada',
            self::Rejected => 'Rejeitada',
            self::UnknownResult => 'Resultado incerto',
            self::Reconciling => 'Reconciliando',
            self::Expired => 'Expirada',
            self::Cancelled => 'Cancelada',
            self::Superseded => 'Substituída',
        };
    }

    public function isReusable(): bool
    {
        return $this === self::Confirmed;
    }

    public function isTerminalSuccess(): bool
    {
        return $this === self::Confirmed;
    }

    public function blocksImmediateRetry(): bool
    {
        return in_array($this, [self::UnknownResult, self::Reconciling, self::Sent], true);
    }

    /** Alias semântico usado pelos services de emissão. */
    public function blocksRetry(): bool
    {
        return $this->blocksImmediateRetry();
    }

    public function isUsableDocument(): bool
    {
        return $this === self::Confirmed;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Rejected, self::Expired, self::Cancelled, self::Superseded => true,
            default => false,
        };
    }
}
