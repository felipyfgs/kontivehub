<?php

namespace App\Enums\Work;

/**
 * Lifecycle de tarefa operacional (mutuamente exclusivo).
 * Riscos (atrasada, multa, etc.) são dimensões separadas.
 *
 * Estados terminais (contam para conclusão do Processo): CONCLUIDA, DISPENSADA.
 */
enum TaskStatus: string
{
    case AFazer = 'A_FAZER';
    case EmProgresso = 'EM_PROGRESSO';
    case Impedida = 'IMPEDIDA';
    case Concluida = 'CONCLUIDA';
    case Dispensada = 'DISPENSADA';

    public function isOpen(): bool
    {
        return match ($this) {
            self::AFazer, self::EmProgresso, self::Impedida => true,
            self::Concluida, self::Dispensada => false,
        };
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    public function countsAsSatisfiedForRequired(): bool
    {
        return $this === self::Concluida || $this === self::Dispensada;
    }
}
