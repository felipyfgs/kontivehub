<?php

namespace App\Enums;

/**
 * Vocabulário honesto de situação fiscal.
 * MUST NOT converter ausência de dado ou fonte em UP_TO_DATE.
 */
enum FiscalSituation: string
{
    case UpToDate = 'UP_TO_DATE';
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Attention = 'ATTENTION';
    case Error = 'ERROR';
    case NotApplicable = 'NOT_APPLICABLE';
    case Unknown = 'UNKNOWN';
    case Unsupported = 'UNSUPPORTED';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::UpToDate => 'Em dia',
            self::Pending => 'Pendente',
            self::Processing => 'Processando',
            self::Attention => 'Atenção',
            self::Error => 'Erro',
            self::NotApplicable => 'Não aplicável',
            self::Unknown => 'Desconhecido',
            self::Unsupported => 'Não suportado',
            self::Blocked => 'Bloqueado',
        };
    }

    /**
     * Situações que exigem evidência oficial positiva para serem atribuídas.
     * Inferência automática é proibida.
     */
    public function requiresPositiveEvidence(): bool
    {
        return match ($this) {
            self::UpToDate, self::Pending, self::Attention, self::NotApplicable => true,
            default => false,
        };
    }

    public function isTerminalFailure(): bool
    {
        return match ($this) {
            self::Error, self::Blocked, self::Unsupported => true,
            default => false,
        };
    }
}
