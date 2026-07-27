<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\QueueBucketResolver;
use App\Domain\Work\WorkRiskCalculator;
use App\Enums\Work\WorkRisk;
use App\Models\WorkTask;
use App\Support\CurrentTenant;
use App\Support\Work\TenantTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Intervalo e dia do calendário operacional — filtros server-side e DTOs tipados.
 */
final class WorkCalendarQuery
{
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
        private readonly QueueBucketResolver $buckets = new QueueBucketResolver,
        private readonly DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    /**
     * @param  array{
     *   from: string,
     *   to: string,
     *   department_id?: int|null,
     *   assignee_membership_id?: int|null,
     *   client_id?: int|null,
     *   status?: string|null,
     *   risk?: string|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function interval(array $filters): array
    {
        $tenant = $this->currentTenant->tenant();
        $tz = TenantTimezone::for($tenant);
        $today = $this->dates->todayInTenant($tz);

        $from = $filters['from'];
        $to = $filters['to'];
        $this->assertRange($from, $to);

        $query = WorkTask::query()
            ->with([
                'process:id,title,client_id,competence,due_date,subject_to_fine,status,tenant_id',
                'process.client:id,legal_name,display_name',
                'department:id,name,code,color',
                'assigneeMembership:id,user_id,tenant_id',
                'assigneeMembership.user:id,name',
            ])
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from, $to]);

        $this->applyFilters($query, $filters);

        $tasks = $query->get();
        $dayMap = [];

        foreach ($tasks as $task) {
            $date = $task->due_date?->format('Y-m-d');
            if ($date === null) {
                continue;
            }

            $enriched = $this->enrich($task, $today);
            if (! $this->matchesRiskFilter($enriched['risks'], $filters['risk'] ?? null)) {
                continue;
            }

            if (! isset($dayMap[$date])) {
                $dayMap[$date] = [
                    'date' => $date,
                    'total' => 0,
                    'overdue' => 0,
                    'fine' => 0,
                    'completed' => 0,
                    'open' => 0,
                    'max_severity' => 0,
                    'items' => [],
                ];
            }

            $dayMap[$date]['total']++;
            if (in_array(WorkRisk::Atrasada->value, $enriched['risks'], true)) {
                $dayMap[$date]['overdue']++;
                $dayMap[$date]['max_severity'] = max($dayMap[$date]['max_severity'], 2);
            }
            if (in_array(WorkRisk::EmMulta->value, $enriched['risks'], true)) {
                $dayMap[$date]['fine']++;
                $dayMap[$date]['max_severity'] = max($dayMap[$date]['max_severity'], 3);
            }
            if ($task->status->isTerminal()) {
                $dayMap[$date]['completed']++;
            } else {
                $dayMap[$date]['open']++;
                $dayMap[$date]['max_severity'] = max($dayMap[$date]['max_severity'], 1);
            }

            // Itens resumidos para Semana (cap por dia) — DTO público sem campos internos.
            if (count($dayMap[$date]['items']) < 25) {
                $dayMap[$date]['items'][] = $this->toPublicRow($enriched);
            }
        }

        ksort($dayMap);

        return [
            'tenant_timezone' => $tz,
            'today' => $today,
            'from' => $from,
            'to' => $to,
            'days' => array_values($dayMap),
        ];
    }

    /**
     * @param  array{
     *   date: string,
     *   department_id?: int|null,
     *   assignee_membership_id?: int|null,
     *   client_id?: int|null,
     *   status?: string|null,
     *   risk?: string|null,
     *   per_page?: int,
     *   page?: int
     * }  $filters
     */
    public function day(array $filters): LengthAwarePaginator
    {
        $tenant = $this->currentTenant->tenant();
        $tz = TenantTimezone::for($tenant);
        $today = $this->dates->todayInTenant($tz);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);

        $query = WorkTask::query()
            ->with([
                'process:id,title,client_id,competence,due_date,subject_to_fine,status,tenant_id',
                'process.client:id,legal_name,display_name',
                'department:id,name,code,color',
                'assigneeMembership:id,user_id,tenant_id',
                'assigneeMembership.user:id,name',
            ])
            ->where('tenant_id', $tenant->id)
            ->whereDate('due_date', $filters['date']);

        $this->applyFilters($query, $filters);

        $enriched = $query->get()
            ->map(fn (WorkTask $t) => $this->enrich($t, $today))
            ->filter(fn (array $row) => $this->matchesRiskFilter($row['risks'], $filters['risk'] ?? null))
            ->sort(function (array $a, array $b): int {
                return $this->buckets->compare(
                    [
                        'bucket' => $a['bucket_enum'],
                        'effective_due' => $a['effective_due_date'],
                        'is_critical' => $a['is_critical'],
                        'created_at' => $a['created_at'] ?? '',
                        'id' => $a['id'],
                    ],
                    [
                        'bucket' => $b['bucket_enum'],
                        'effective_due' => $b['effective_due_date'],
                        'is_critical' => $b['is_critical'],
                        'created_at' => $b['created_at'] ?? '',
                        'id' => $b['id'],
                    ],
                );
            })
            ->values();

        $total = $enriched->count();
        $slice = $enriched->slice(($page - 1) * $perPage, $perPage)
            ->map(fn (array $row) => $this->toPublicRow($row))
            ->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  Builder<WorkTask>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['department_id'])) {
            $query->where('work_department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['assignee_membership_id'])) {
            $query->where('assignee_membership_id', (int) $filters['assignee_membership_id']);
        }
        if (! empty($filters['client_id'])) {
            $clientId = (int) $filters['client_id'];
            $query->whereHas('process', fn ($q) => $q->where('client_id', $clientId));
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function enrich(WorkTask $task, string $today): array
    {
        $process = $task->process;
        $effectiveDue = $this->risks->effectiveDueDate(
            $task->due_date?->format('Y-m-d'),
            $process?->target_due_date?->format('Y-m-d'),
            $process?->due_date?->format('Y-m-d'),
        );
        $riskList = $this->risks->forTask(
            $task->status,
            $task->due_date?->format('Y-m-d'),
            $process?->target_due_date?->format('Y-m-d'),
            $process?->due_date?->format('Y-m-d'),
            (bool) ($process?->subject_to_fine),
            $task->assignee_membership_id,
            $today,
        );
        $riskValues = array_map(fn (WorkRisk $r) => $r->value, $riskList);
        $bucket = $this->buckets->resolve($task->status, $riskList, $effectiveDue, $today);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'effective_due_date' => $effectiveDue,
            'is_critical' => (bool) $task->is_critical,
            'is_required' => (bool) $task->is_required,
            'requires_evidence' => (bool) $task->requires_evidence,
            'block_reason' => $task->block_reason,
            'lock_version' => $task->lock_version,
            'bucket' => $bucket->value,
            'bucket_enum' => $bucket,
            'risks' => $riskValues,
            'created_at' => (string) $task->created_at,
            'department' => $task->department ? [
                'id' => $task->department->id,
                'name' => $task->department->name,
                'code' => $task->department->code,
            ] : null,
            'assignee' => $task->assigneeMembership?->user ? [
                'membership_id' => $task->assigneeMembership->id,
                'name' => $task->assigneeMembership->user->name,
            ] : null,
            'process' => $process ? [
                'id' => $process->id,
                'title' => $process->title,
                'competence' => $process->competence,
                'status' => $process->status->value,
                'subject_to_fine' => (bool) $process->subject_to_fine,
                'client' => $process->client ? [
                    'id' => $process->client->id,
                    'name' => $process->client->display_name ?: $process->client->legal_name,
                ] : null,
            ] : null,
        ];
    }

    /**
     * Remove campos internos de ordenação antes de serializar a API.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toPublicRow(array $row): array
    {
        unset($row['bucket_enum'], $row['created_at']);

        return $row;
    }

    /**
     * @param  list<string>  $risks
     */
    private function matchesRiskFilter(array $risks, ?string $risk): bool
    {
        if ($risk === null || $risk === '') {
            return true;
        }

        return in_array($risk, $risks, true);
    }

    private function assertRange(string $from, string $to): void
    {
        try {
            $fromDate = CarbonImmutable::createFromFormat('Y-m-d', $from);
            $toDate = CarbonImmutable::createFromFormat('Y-m-d', $to);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'from' => ['Intervalo de datas inválido.'],
            ]);
        }

        if ($fromDate === false || $toDate === false || $fromDate->gt($toDate)) {
            throw ValidationException::withMessages([
                'from' => ['Intervalo de datas inválido (from > to).'],
            ]);
        }

        if ($fromDate->diffInDays($toDate) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Intervalo máximo do calendário é de '.self::MAX_RANGE_DAYS.' dias.'],
            ]);
        }
    }
}
