<?php

namespace App\Enums;

/**
 * Frescor derivado na leitura (não persiste).
 * CURRENT: até 7 dias após última consulta válida; OUTDATED depois.
 */
enum PgmeiFreshnessState: string
{
    case Current = 'CURRENT';
    case Outdated = 'OUTDATED';
    case Unknown = 'UNKNOWN';
}
