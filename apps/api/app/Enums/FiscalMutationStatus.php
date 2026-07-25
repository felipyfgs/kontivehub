<?php

namespace App\Enums;

/**
 * Máquina de estados de operação fiscal mutante.
 *
 * PENDING → SENT → CONFIRMED | REJECTED | UNKNOWN_RESULT
 * UNKNOWN_RESULT → RECONCILING → CONFIRMED | REJECTED | UNKNOWN_RESULT
 */
enum FiscalMutationStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case UnknownResult = 'UNKNOWN_RESULT';
    case Reconciling = 'RECONCILING';

    public function isTerminal(): bool
    {
        return $this === self::Confirmed || $this === self::Rejected;
    }

    /** Bloqueia novo retry cego (exige reconciliação ou já terminal). */
    public function blocksBlindRetry(): bool
    {
        return match ($this) {
            self::Sent, self::UnknownResult, self::Reconciling => true,
            self::Confirmed, self::Rejected => true,
            self::Pending => false,
        };
    }

    /** Resultado incerto — precisa reconciliar antes de nova mutação equivalente. */
    public function isUncertain(): bool
    {
        return match ($this) {
            self::Sent, self::UnknownResult, self::Reconciling => true,
            default => false,
        };
    }

    public function allowsReconciliation(): bool
    {
        return match ($this) {
            self::Sent, self::UnknownResult, self::Reconciling => true,
            default => false,
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Rejected, self::UnknownResult],
            self::Sent => [self::Confirmed, self::Rejected, self::UnknownResult, self::Reconciling],
            self::UnknownResult => [self::Reconciling],
            self::Reconciling => [self::Confirmed, self::Rejected, self::UnknownResult],
            self::Confirmed, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Sent => 'Enviada',
            self::Confirmed => 'Confirmada',
            self::Rejected => 'Rejeitada',
            self::UnknownResult => 'Resultado incerto',
            self::Reconciling => 'Reconciliando',
        };
    }
}
