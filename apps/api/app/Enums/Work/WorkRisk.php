<?php

namespace App\Enums\Work;

/**
 * Dimensões de risco combináveis (não substituem o lifecycle).
 */
enum WorkRisk: string
{
    case Atrasada = 'ATRASADA';
    case EmMulta = 'EM_MULTA';
    case SemPrazo = 'SEM_PRAZO';
    case SemResponsavel = 'SEM_RESPONSAVEL';
}
