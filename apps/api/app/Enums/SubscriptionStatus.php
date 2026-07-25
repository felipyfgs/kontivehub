<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case PendingActivation = 'PENDING_ACTIVATION';
    case Trial = 'TRIAL';
    case Active = 'ACTIVE';
    case PastDue = 'PAST_DUE';
    case Suspended = 'SUSPENDED';
    case Canceled = 'CANCELED';

    /** Leitura de histórico/evidência autorizada permanece em todos os estados. */
    public function allowsRead(): bool
    {
        return true;
    }

    /**
     * Mutações de domínio e jobs externos: bloqueados em PENDING_ACTIVATION/SUSPENDED/CANCELED.
     * PAST_DUE ainda permite operação com restrição comercial futura.
     */
    public function allowsMutations(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::PendingActivation, self::Suspended, self::Canceled => false,
        };
    }

    public function allowsExternalCalls(): bool
    {
        return $this->allowsMutations();
    }

    public function isTerminal(): bool
    {
        return $this === self::Canceled;
    }
}
