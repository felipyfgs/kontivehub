<?php

namespace App\Enums;

/**
 * Estado observacional de dívida ativa PGMEI no ano consultado.
 * UNVERIFIED = sem consulta produtiva válida ou resposta ambígua.
 */
enum PgmeiDebtState: string
{
    case HasActiveDebt = 'HAS_ACTIVE_DEBT';
    case NoActiveDebt = 'NO_ACTIVE_DEBT';
    case Unverified = 'UNVERIFIED';
}
