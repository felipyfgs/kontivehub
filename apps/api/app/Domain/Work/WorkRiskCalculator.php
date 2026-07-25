<?php

namespace App\Domain\Work;

use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;

/**
 * Dimensões de risco combináveis para fila, KPIs e export.
 *
 * Precedência do prazo efetivo operacional:
 * tarefa.due_date → process.target_due_date → process.due_date (legal).
 *
 * ATRASADA usa o prazo efetivo; EM_MULTA usa o prazo legal do processo.
 */
final class WorkRiskCalculator
{
    /**
     * @return list<WorkRisk>
     */
    public function forTask(
        TaskStatus $status,
        ?string $taskDueDate,
        ?string $processTargetDueDate,
        ?string $processLegalDueDate,
        bool $processSubjectToFine,
        ?int $assigneeMembershipId,
        string $todayYmd,
    ): array {
        $risks = [];

        if ($status->isTerminal()) {
            return $risks;
        }

        $effectiveDue = $this->effectiveDueDate($taskDueDate, $processTargetDueDate, $processLegalDueDate);
        $legalDue = $this->nonEmpty($processLegalDueDate);

        if ($effectiveDue === null && $legalDue === null) {
            $risks[] = WorkRisk::SemPrazo;
        } else {
            if ($effectiveDue !== null && $effectiveDue < $todayYmd) {
                $risks[] = WorkRisk::Atrasada;
            }
            if ($processSubjectToFine && $legalDue !== null && $legalDue < $todayYmd) {
                $risks[] = WorkRisk::EmMulta;
            }
        }

        if ($assigneeMembershipId === null) {
            $risks[] = WorkRisk::SemResponsavel;
        }

        return $risks;
    }

    /**
     * Prazo efetivo operacional: tarefa → meta interna do processo → prazo legal.
     */
    public function effectiveDueDate(
        ?string $taskDueDate,
        ?string $processTargetDueDate = null,
        ?string $processLegalDueDate = null,
    ): ?string {
        foreach ([$taskDueDate, $processTargetDueDate, $processLegalDueDate] as $candidate) {
            $value = $this->nonEmpty($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
