<?php

namespace App\Enums;

/**
 * Ciclo de vida operacional do tenant (ortogonal à assinatura comercial).
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D10
 */
enum OfficeLifecycleStatus: string
{
    case PendingActivation = 'PENDING_ACTIVATION';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Deprovisioned = 'DEPROVISIONED';

    public function isPending(): bool
    {
        return $this === self::PendingActivation;
    }

    public function isOperational(): bool
    {
        return $this === self::Active;
    }

    public function isSelectable(): bool
    {
        return $this === self::Active;
    }

    public function isTerminal(): bool
    {
        return $this === self::Deprovisioned;
    }

    /**
     * Transições válidas da máquina de estados (D10).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingActivation => [self::Active, self::Deprovisioned],
            self::Active => [self::Suspended],
            self::Suspended => [self::Active, self::Deprovisioned],
            self::Deprovisioned => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
