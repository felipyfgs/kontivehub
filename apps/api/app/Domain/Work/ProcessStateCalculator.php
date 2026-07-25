<?php

namespace App\Domain\Work;

use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;

/**
 * Deriva o Status do Processo a partir das Tarefas (único caminho de progresso).
 *
 * Estados terminais de tarefa: {@see TaskStatus::Concluida}, {@see TaskStatus::Dispensada}.
 *
 * Regras:
 * - alguma IMPEDIDA → IMPEDIDO
 * - todas terminais (CONCLUIDA|DISPENSADA) → CONCLUIDO
 * - todas A_FAZER → A_FAZER
 * - demais → EM_PROGRESSO
 *
 * Arquivamento é ortogonal ({@see OperationalProcess::$archived_at}) e não entra no enum.
 */
final class ProcessStateCalculator
{
    /**
     * @param  list<array{status: TaskStatus|string, is_required?: bool, is_critical?: bool}>  $tasks
     */
    public function derive(array $tasks, ?ProcessStatus $current = null): ProcessStatus
    {
        unset($current);

        if ($tasks === []) {
            return ProcessStatus::AFazer;
        }

        $normalized = array_map(function (array $t): TaskStatus {
            return $t['status'] instanceof TaskStatus
                ? $t['status']
                : TaskStatus::from((string) $t['status']);
        }, $tasks);

        foreach ($normalized as $status) {
            if ($status === TaskStatus::Impedida) {
                return ProcessStatus::Impedido;
            }
        }

        $allTerminal = true;
        $allToDo = true;
        foreach ($normalized as $status) {
            if (! $status->isTerminal()) {
                $allTerminal = false;
            }
            if ($status !== TaskStatus::AFazer) {
                $allToDo = false;
            }
        }

        if ($allTerminal) {
            return ProcessStatus::Concluido;
        }

        if ($allToDo) {
            return ProcessStatus::AFazer;
        }

        return ProcessStatus::EmProgresso;
    }
}
