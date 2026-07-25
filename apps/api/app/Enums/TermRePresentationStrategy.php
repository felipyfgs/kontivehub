<?php

namespace App\Enums;

/**
 * Estratégia de reapresentação do Termo assinado após expirar o token do procurador.
 * Default conservador: ACTION_REQUIRED até validação em trial/piloto.
 */
enum TermRePresentationStrategy: string
{
    /** Reapresenta o Termo armazenado automaticamente se ainda vigente. */
    case ReuseStoredTerm = 'REUSE_STORED_TERM';

    /** Exige nova assinatura interativa (A3 ou reenvio). */
    case RequireNewSignature = 'REQUIRE_NEW_SIGNATURE';

    /** Ainda não validado no ambiente — bloqueia renovação silenciosa. */
    case PendingValidation = 'PENDING_VALIDATION';
}
