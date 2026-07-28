<?php

namespace App\Services\Work;

use App\DTO\Work\WorkProcessFiltersData;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\WorkProcess;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class WorkProcessQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private WorkProcessViewBuilder $views,
    ) {}

    public function paginate(
        WorkProcessFiltersData $data,
    ): LengthAwarePaginator {
        $filters = $data->filters;
        $includeTasks = $data->includeTasks;
        $query = WorkProcess::query()
            ->with(array_values(array_filter([
                'client:id,legal_name,display_name,root_cnpj',
                'client.establishments:id,client_id,cnpj,is_headquarters',
                $includeTasks ? 'tasks.department:id,name,code' : null,
                $includeTasks ? 'tasks.assigneeMembership:id,user_id,tenant_id' : null,
                $includeTasks ? 'tasks.assigneeMembership.user:id,name' : null,
                'department:id,name,code',
                'assigneeMembership:id,user_id,tenant_id',
                'assigneeMembership.user:id,name',
            ])))
            ->where('tenant_id', $this->currentTenant->id());

        if (! $includeTasks) {
            $terminal = [
                TaskStatus::Concluida->value,
                TaskStatus::Dispensada->value,
            ];
            $query->withCount([
                'tasks',
                'tasks as completed_task_count' => fn (Builder $tasks) => $tasks
                    ->whereIn('status', $terminal),
                'tasks as open_task_count' => fn (Builder $tasks) => $tasks
                    ->whereNotIn('status', $terminal),
            ]);
        }

        $this->applyFilters($query, $filters);

        $sort = (string) ($filters['sort'] ?? 'id');
        $direction = ($filters['direction'] ?? 'desc') === 'asc'
            ? 'asc'
            : 'desc';
        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        $paginator = $query->paginate(
            perPage: (int) ($filters['per_page'] ?? 25),
            page: (int) ($filters['page'] ?? 1),
        );

        return $paginator->through(
            fn (WorkProcess $process) => $this->views->fromLoaded(
                $process,
                includeTasks: $includeTasks,
            ),
        );
    }

    /**
     * @param  Builder<WorkProcess>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! ($filters['include_archived'] ?? false)) {
            $query->notArchived();
        }
        if (! empty($filters['competence'])) {
            $query->where('competence', $filters['competence']);
        }
        if (! empty($filters['reference_period'])) {
            $query->where('competence', $filters['reference_period']);
        }
        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }
        if ($filters['without_template'] ?? false) {
            $query->whereNull('work_process_template_id');
        } elseif (! empty($filters['work_process_template_id'])) {
            $query->where(
                'work_process_template_id',
                (int) $filters['work_process_template_id'],
            );
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($filters['active_only'] ?? false) {
            $query->notArchived()
                ->where('status', '!=', ProcessStatus::Concluido->value);
        }
        if (! empty($filters['department_id'])) {
            $query->where('work_department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['assignee_membership_id'])) {
            $query->where(
                'assignee_membership_id',
                (int) $filters['assignee_membership_id'],
            );
        }
        if (! empty($filters['q'])) {
            $needle = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function (Builder $search) use ($needle): void {
                $search->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereHas('client', function (Builder $client) use ($needle): void {
                        $client->whereRaw('LOWER(legal_name) LIKE ?', [$needle])
                            ->orWhereRaw(
                                'LOWER(COALESCE(display_name, \'\')) LIKE ?',
                                [$needle],
                            );
                    });
            });
        }
    }
}
