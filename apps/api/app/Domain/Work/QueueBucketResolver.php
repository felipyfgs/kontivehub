<?php

namespace App\Domain\Work;

use App\Enums\Work\QueueBucket;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use DateTimeImmutable;

/**
 * Resolve o bucket da fila e ordenação estável dentro do bucket.
 */
final class QueueBucketResolver
{
    public function __construct(
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
    ) {}

    /**
     * @param  list<WorkRisk>  $risks
     */
    public function resolve(
        TaskStatus $status,
        array $risks,
        ?string $effectiveDueDate,
        string $todayYmd,
    ): QueueBucket {
        if ($status->isTerminal()) {
            return QueueBucket::Concluidas;
        }

        $riskValues = array_map(fn (WorkRisk $r) => $r->value, $risks);

        if (in_array(WorkRisk::EmMulta->value, $riskValues, true)) {
            return QueueBucket::EmMulta;
        }

        if (in_array(WorkRisk::Atrasada->value, $riskValues, true)) {
            return QueueBucket::Atrasada;
        }

        if ($effectiveDueDate === $todayYmd) {
            return QueueBucket::VenceHoje;
        }

        if ($effectiveDueDate !== null && $effectiveDueDate !== '') {
            $today = new DateTimeImmutable($todayYmd);
            $due = new DateTimeImmutable($effectiveDueDate);
            $diff = (int) $today->diff($due)->format('%r%a');
            if ($diff >= 1 && $diff <= 3) {
                return QueueBucket::VenceEmTresDias;
            }
        }

        if ($status === TaskStatus::Impedida) {
            return QueueBucket::Impedida;
        }

        if (in_array(WorkRisk::SemResponsavel->value, $riskValues, true)) {
            return QueueBucket::SemResponsavel;
        }

        return QueueBucket::DemaisAbertas;
    }

    /**
     * Comparador estável: bucket rank → prazo asc → crítica desc → created_at asc → id asc.
     *
     * @param  array{
     *   bucket: QueueBucket,
     *   effective_due: ?string,
     *   is_critical: bool,
     *   created_at: string,
     *   id: int
     * }  $a
     * @param  array{
     *   bucket: QueueBucket,
     *   effective_due: ?string,
     *   is_critical: bool,
     *   created_at: string,
     *   id: int
     * }  $b
     */
    public function compare(array $a, array $b): int
    {
        $rank = $a['bucket']->sortRank() <=> $b['bucket']->sortRank();
        if ($rank !== 0) {
            return $rank;
        }

        $dueA = $a['effective_due'] ?? '9999-12-31';
        $dueB = $b['effective_due'] ?? '9999-12-31';
        $due = $dueA <=> $dueB;
        if ($due !== 0) {
            return $due;
        }

        $crit = ((int) $b['is_critical']) <=> ((int) $a['is_critical']);
        if ($crit !== 0) {
            return $crit;
        }

        $created = $a['created_at'] <=> $b['created_at'];
        if ($created !== 0) {
            return $created;
        }

        return $a['id'] <=> $b['id'];
    }
}
