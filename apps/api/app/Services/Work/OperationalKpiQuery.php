<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\WorkRiskCalculator;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Support\CurrentOffice;
use App\Support\Work\OfficeTimezone;

/**
 * KPIs e listas de risco do bloco operacional do dashboard.
 * Agregados por departamento com métricas nomeadas e denominador explícito.
 */
final class OperationalKpiQuery
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
        private readonly DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $office = $this->currentOffice->office();
        $tz = OfficeTimezone::for($office);
        $today = $this->dates->todayInOffice($tz);

        $openStatuses = [
            TaskStatus::AFazer->value,
            TaskStatus::EmProgresso->value,
            TaskStatus::Impedida->value,
        ];

        $tasks = OperationalTask::query()
            ->with('process:id,due_date,subject_to_fine,client_id,office_id')
            ->where('office_id', $office->id)
            ->whereIn('status', array_merge($openStatuses, [
                TaskStatus::Concluida->value,
                TaskStatus::Dispensada->value,
            ]))
            ->get();

        $totalOpen = 0;
        $atrasadas = 0;
        $emMulta = 0;
        $venceHoje = 0;
        $emProgresso = 0;
        $concluidas = 0;
        $semResponsavel = 0;
        $riskRows = [];

        /** @var array<string, array{work_department_id: int|null, open: int, completed: int, overdue: int, fine: int, unassigned: int, total_relevant: int}> */
        $deptAgg = [];

        foreach ($tasks as $task) {
            $deptKey = $task->work_department_id === null ? 'null' : (string) $task->work_department_id;
            if (! isset($deptAgg[$deptKey])) {
                $deptAgg[$deptKey] = [
                    'work_department_id' => $task->work_department_id,
                    'open' => 0,
                    'completed' => 0,
                    'overdue' => 0,
                    'fine' => 0,
                    'unassigned' => 0,
                    'total_relevant' => 0,
                ];
            }

            if ($task->status->isTerminal()) {
                if ($task->status === TaskStatus::Concluida) {
                    $concluidas++;
                    $deptAgg[$deptKey]['completed']++;
                    $deptAgg[$deptKey]['total_relevant']++;
                }

                continue;
            }

            $totalOpen++;
            $deptAgg[$deptKey]['open']++;
            $deptAgg[$deptKey]['total_relevant']++;

            if ($task->status === TaskStatus::EmProgresso) {
                $emProgresso++;
            }

            $effective = $this->risks->effectiveDueDate(
                $task->due_date?->format('Y-m-d'),
                $task->process?->target_due_date?->format('Y-m-d'),
                $task->process?->due_date?->format('Y-m-d'),
            );
            $riskList = $this->risks->forTask(
                $task->status,
                $task->due_date?->format('Y-m-d'),
                $task->process?->target_due_date?->format('Y-m-d'),
                $task->process?->due_date?->format('Y-m-d'),
                (bool) ($task->process?->subject_to_fine),
                $task->assignee_membership_id,
                $today,
            );
            $values = array_map(fn (WorkRisk $r) => $r->value, $riskList);

            if (in_array(WorkRisk::Atrasada->value, $values, true)) {
                $atrasadas++;
                $deptAgg[$deptKey]['overdue']++;
            }
            if (in_array(WorkRisk::EmMulta->value, $values, true)) {
                $emMulta++;
                $deptAgg[$deptKey]['fine']++;
            }
            if ($effective === $today) {
                $venceHoje++;
            }
            if (in_array(WorkRisk::SemResponsavel->value, $values, true)) {
                $semResponsavel++;
                $deptAgg[$deptKey]['unassigned']++;
            }

            if ($riskList !== []) {
                $riskRows[] = [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'process_id' => $task->operational_process_id,
                    'risks' => $values,
                    'effective_due_date' => $effective,
                ];
            }
        }

        usort($riskRows, function (array $a, array $b): int {
            $score = static function (array $r): int {
                $s = 0;
                if (in_array(WorkRisk::EmMulta->value, $r['risks'], true)) {
                    $s += 100;
                }
                if (in_array(WorkRisk::Atrasada->value, $r['risks'], true)) {
                    $s += 50;
                }

                return $s;
            };

            return $score($b) <=> $score($a);
        });

        $byDepartment = collect($deptAgg)
            ->map(function (array $row): array {
                $denom = max($row['total_relevant'], 1);
                $completedPct = $row['total_relevant'] > 0
                    ? (int) round(($row['completed'] / $denom) * 100)
                    : 0;

                return [
                    'work_department_id' => $row['work_department_id'],
                    'open' => $row['open'],
                    'completed' => $row['completed'],
                    'overdue' => $row['overdue'],
                    'fine' => $row['fine'],
                    'unassigned' => $row['unassigned'],
                    'total_relevant' => $row['total_relevant'],
                    'completed_percent' => $completedPct,
                    // Compat legado
                    'total' => $row['open'],
                ];
            })
            ->values()
            ->all();

        $byAssignee = OperationalTask::query()
            ->selectRaw('assignee_membership_id, count(*) as total')
            ->where('office_id', $office->id)
            ->whereIn('status', $openStatuses)
            ->groupBy('assignee_membership_id')
            ->get()
            ->map(fn ($r) => [
                'assignee_membership_id' => $r->assignee_membership_id,
                'total' => (int) $r->total,
            ])
            ->all();

        $processesWithoutOwner = OperationalProcess::query()
            ->where('office_id', $office->id)
            ->whereNull('assignee_membership_id')
            ->notArchived()
            ->where('status', '!=', ProcessStatus::Concluido->value)
            ->orderBy('due_date')
            ->limit(10)
            ->get(['id', 'title', 'competence', 'due_date', 'client_id'])
            ->map(fn (OperationalProcess $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'competence' => $p->competence,
                'due_date' => $p->due_date?->format('Y-m-d'),
                'client_id' => $p->client_id,
            ])
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'office_timezone' => $tz,
            'today' => $today,
            'kpis' => [
                'total_open' => $totalOpen,
                'atrasadas' => $atrasadas,
                'em_multa' => $emMulta,
                'vence_hoje' => $venceHoje,
                'em_progresso' => $emProgresso,
                'concluidas' => $concluidas,
                'sem_responsavel' => $semResponsavel,
            ],
            'by_department' => $byDepartment,
            'by_assignee' => $byAssignee,
            'top_risks' => array_slice($riskRows, 0, 15),
            'processes_without_owner' => $processesWithoutOwner,
            'filters_effective' => [
                'office_id' => $office->id,
                'today' => $today,
                'timezone' => $tz,
            ],
        ];
    }
}
