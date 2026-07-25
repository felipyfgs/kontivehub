<?php

namespace App\Enums;

/**
 * Estados duráveis de recuperação SVRS por chave.
 *
 * @see design D4 — add-svrs-nfce-outbound-xml-retrieval
 */
enum SvrsNfceRecoveryStatus: string
{
    case Eligible = 'ELIGIBLE';
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case RetryScheduled = 'RETRY_SCHEDULED';
    case Captured = 'CAPTURED';
    case NotAvailableVisible = 'NOT_AVAILABLE_VISIBLE';
    case Blocked = 'BLOCKED';
    /** Resolvido por outra fonte (upload/pacote) — encerra jobs futuros. */
    case ResolvedByOtherSource = 'RESOLVED_BY_OTHER_SOURCE';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Captured,
            self::NotAvailableVisible,
            self::Blocked,
            self::ResolvedByOtherSource,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    public function label(): string
    {
        return match ($this) {
            self::Eligible => 'Elegível',
            self::Queued => 'Na fila',
            self::Running => 'Em recuperação',
            self::RetryScheduled => 'Retry agendado',
            self::Captured => 'Capturado',
            self::NotAvailableVisible => 'Indisponível (visível)',
            self::Blocked => 'Bloqueado',
            self::ResolvedByOtherSource => 'Resolvido por outra fonte',
        };
    }
}
