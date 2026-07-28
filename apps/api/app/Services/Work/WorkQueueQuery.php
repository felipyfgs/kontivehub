<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\QueueBucketResolver;
use App\Domain\Work\WorkRiskCalculator;
use App\DTO\Work\WorkTaskQueueItemData;
use App\Enums\TenantRole;
use App\Enums\Work\QueueBucket;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use App\Models\TenantMembership;
use App\Models\WorkTask;
use App\Support\CurrentTenant;
use App\Support\Work\TenantTimezone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fila determinística “Minha fila” com buckets e filtros.
 */
final class WorkQueueQuery
{
    public const SORT_WHITELIST = [
        'title',
        'status',
        'effective_due_date',
        'client_name',
        'assignee_name',
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
        private readonly QueueBucketResolver $buckets = new QueueBucketResolver,
        private readonly DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    /**
     * @param  array{
     *   tab?: string,
     *   department_id?: int|null,
     *   assignee_membership_id?: int|null,
     *   client_id?: int|null,
     *   q?: string|null,
     *   per_page?: int,
     *   page?: int,
     *   scope?: string,
     *   sort?: string|null,
     *   direction?: string|null
     * }  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $tenant = $this->currentTenant->tenant();
        $tz = TenantTimezone::for($tenant);
        $today = $this->dates->todayInTenant($tz);
        $role = $this->currentTenant->role();
        $membership = $this->currentTenant->realMembership();

        $query = WorkTask::query()
            ->with([
                'process:id,title,client_id,competence,due_date,subject_to_fine,status,tenant_id',
                'process.client:id,legal_name,display_name,root_cnpj',
                'department:id,name,code,color',
                'assigneeMembership:id,user_id,tenant_id',
                'assigneeMembership.user:id,name',
            ])
            ->where('work_tasks.tenant_id', $tenant->id);

        $tab = $filters['tab'] ?? 'open';
        if ($tab === 'concluidas') {
            $query->whereIn('status', [TaskStatus::Concluida->value, TaskStatus::Dispensada->value]);
        } elseif ($tab === 'impedidas') {
            $query->where('status', TaskStatus::Impedida->value);
        } elseif ($tab === 'todas') {
            // Conjunto do board Kanban: quatro colunas, sem DISPENSADA.
            $query->whereIn('status', [
                TaskStatus::AFazer->value,
                TaskStatus::EmProgresso->value,
                TaskStatus::Impedida->value,
                TaskStatus::Concluida->value,
            ]);
        } else {
            // open (default) / hoje / atrasadas / semana → status abertos
            $query->whereIn('status', [
                TaskStatus::AFazer->value,
                TaskStatus::EmProgresso->value,
                TaskStatus::Impedida->value,
            ]);
        }

        $scope = is_string($filters['scope'] ?? null) ? $filters['scope'] : 'mine';
        if ($role === TenantRole::TenantUser && $membership !== null) {
            $this->applyOperatorScope($query, $scope, $membership);
        }

        if (! empty($filters['department_id'])) {
            $query->where('work_department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['assignee_membership_id'])) {
            $query->where('assignee_membership_id', (int) $filters['assignee_membership_id']);
        }
        if (! empty($filters['client_id'])) {
            $query->whereHas('process', fn (Builder $q) => $q->where('client_id', (int) $filters['client_id']));
        }
        if (! empty($filters['q'])) {
            $needle = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function (Builder $q) use ($needle): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereHas('process', fn (Builder $p) => $p->whereRaw('LOWER(title) LIKE ?', [$needle]));
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $tasks = $query->get();

        $enriched = $tasks->map(function (WorkTask $task) use ($today) {
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
            $bucket = $this->buckets->resolve($task->status, $riskList, $effectiveDue, $today);

            // Filtro de aba por bucket
            return [
                'task' => $task,
                'bucket' => $bucket,
                'risks' => array_map(fn (WorkRisk $r) => $r->value, $riskList),
                'effective_due' => $effectiveDue,
                'sort' => [
                    'bucket' => $bucket,
                    'effective_due' => $effectiveDue,
                    'is_critical' => (bool) $task->is_critical,
                    'created_at' => (string) $task->created_at,
                    'id' => (int) $task->id,
                ],
            ];
        });

        $tabFilter = $filters['tab'] ?? null;
        if ($tabFilter === 'hoje') {
            $enriched = $enriched->filter(fn ($i) => $i['bucket'] === QueueBucket::VenceHoje
                || $i['bucket'] === QueueBucket::EmMulta
                || $i['bucket'] === QueueBucket::Atrasada);
        } elseif ($tabFilter === 'atrasadas') {
            $enriched = $enriched->filter(fn ($i) => in_array(WorkRisk::Atrasada->value, $i['risks'], true)
                || in_array(WorkRisk::EmMulta->value, $i['risks'], true));
        } elseif ($tabFilter === 'semana') {
            $enriched = $enriched->filter(fn ($i) => in_array($i['bucket'], [
                QueueBucket::VenceHoje,
                QueueBucket::VenceEmTresDias,
                QueueBucket::Atrasada,
                QueueBucket::EmMulta,
            ], true));
        }

        $sorted = $this->sortEnriched($enriched, $filters)->values();

        $page = max((int) ($filters['page'] ?? 1), 1);
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $items = $slice->map(
            fn ($row) => new WorkTaskQueueItemData(
                task: $row['task'],
                bucket: $row['bucket']->value,
                risks: $row['risks'],
                effectiveDueDate: $row['effective_due'],
            ),
        );

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $enriched
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function sortEnriched($enriched, array $filters)
    {
        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : null;
        if ($sort === null || $sort === '' || ! in_array($sort, self::SORT_WHITELIST, true)) {
            return $enriched->sort(fn ($a, $b) => $this->buckets->compare($a['sort'], $b['sort']));
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $factor = $direction === 'desc' ? -1 : 1;

        return $enriched->sort(function ($a, $b) use ($sort, $factor) {
            $left = $this->sortValue($a, $sort);
            $right = $this->sortValue($b, $sort);
            $cmp = $this->compareSortValues($left, $right);
            if ($cmp !== 0) {
                return $cmp * $factor;
            }

            return ((int) $a['task']->id) <=> ((int) $b['task']->id);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sortValue(array $row, string $sort): mixed
    {
        $task = $row['task'];

        return match ($sort) {
            'title' => mb_strtolower((string) $task->title),
            'status' => (string) $task->status->value,
            'effective_due_date' => $row['effective_due'] ?? null,
            'client_name' => mb_strtolower((string) (
                $task->process?->client?->display_name
                ?: $task->process?->client?->legal_name
                ?: ''
            )),
            'assignee_name' => mb_strtolower((string) ($task->assigneeMembership?->user?->name ?? '')),
            default => null,
        };
    }

    private function compareSortValues(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }
        if ($left === null) {
            return 1;
        }
        if ($right === null) {
            return -1;
        }

        return $left <=> $right;
    }

    /**
     * @param  Builder<WorkTask>  $query
     */
    private function applyOperatorScope(Builder $query, string $scope, TenantMembership $membership): void
    {
        match ($scope) {
            'tenant' => null,
            'mine' => $query->where('assignee_membership_id', $membership->id),
            'department' => $this->applyDepartmentScope($query, $membership),
            default => $query->where('assignee_membership_id', $membership->id),
        };
    }

    /**
     * @param  Builder<WorkTask>  $query
     */
    private function applyDepartmentScope(Builder $query, TenantMembership $membership): void
    {
        if ($membership->work_department_id === null) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where('work_department_id', $membership->work_department_id);
    }
}
