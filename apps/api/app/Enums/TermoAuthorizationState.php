<?php

namespace App\Enums;

/**
 * Estado do Termo / representação do Autor do Pedido.
 * LOCAL_VALIDATED ≠ aceite SERPRO. SERPRO_ACCEPTED só via resposta real.
 */
enum TermoAuthorizationState: string
{
    case Draft = 'DRAFT';
    case Signed = 'SIGNED';
    case LocalValidated = 'LOCAL_VALIDATED';
    case SerproAccepted = 'SERPRO_ACCEPTED';
    case Rejected = 'REJECTED';
    case Pending = 'PENDING';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    /**
     * Estados que NÃO equivalem a aceite remoto real.
     */
    public function isRemoteAccepted(): bool
    {
        return $this === self::SerproAccepted;
    }
}
