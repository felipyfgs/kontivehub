<?php

namespace App\Enums\Work;

/**
 * Buckets determinísticos da fila “Minha fila” (ordem de prioridade).
 */
enum QueueBucket: string
{
    case EmMulta = 'EM_MULTA';
    case Atrasada = 'ATRASADA';
    case VenceHoje = 'VENCE_HOJE';
    case VenceEmTresDias = 'VENCE_EM_TRES_DIAS';
    case Impedida = 'IMPEDIDA';
    case SemResponsavel = 'SEM_RESPONSAVEL';
    case DemaisAbertas = 'DEMAIS_ABERTAS';
    case Concluidas = 'CONCLUIDAS';

    /**
     * Ordem estável de prioridade (menor = mais urgente).
     */
    public function sortRank(): int
    {
        return match ($this) {
            self::EmMulta => 1,
            self::Atrasada => 2,
            self::VenceHoje => 3,
            self::VenceEmTresDias => 4,
            self::Impedida => 5,
            self::SemResponsavel => 6,
            self::DemaisAbertas => 7,
            self::Concluidas => 99,
        };
    }
}
