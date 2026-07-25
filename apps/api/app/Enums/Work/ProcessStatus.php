<?php

namespace App\Enums\Work;

/**
 * Status derivado do Processo — progresso operacional (não inclui arquivamento).
 * Arquivamento é ortogonal via `archived_at`.
 */
enum ProcessStatus: string
{
    case AFazer = 'A_FAZER';
    case EmProgresso = 'EM_PROGRESSO';
    case Impedido = 'IMPEDIDO';
    case Concluido = 'CONCLUIDO';
}
