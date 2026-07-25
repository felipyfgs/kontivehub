<?php

namespace App\Enums;

/**
 * Política de aprovação de ações sensíveis SERPRO (plataforma).
 *
 * Allowlist fechada no serviço — não reduzir globalmente o número de aprovadores.
 */
enum SerproApprovalPolicy: string
{
    /** Confirmação reforçada do proprietário único (senha recente + frase + motivo + janela). */
    case OwnerConfirmation = 'OWNER_CONFIRMATION';

    /** Duas pessoas com papéis distintos (canário/promoção). */
    case DualRole = 'DUAL_ROLE';

    public function label(): string
    {
        return match ($this) {
            self::OwnerConfirmation => 'Confirmação do proprietário',
            self::DualRole => 'Dois papéis distintos',
        };
    }

    public function requiresSecondApprover(): bool
    {
        return $this === self::DualRole;
    }
}
