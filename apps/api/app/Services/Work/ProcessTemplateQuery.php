<?php

namespace App\Services\Work;

use App\DTO\Work\ProcessTemplateFiltersData;
use App\Models\WorkProcessTemplate;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProcessTemplateQuery
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /** @return LengthAwarePaginator<int, WorkProcessTemplate> */
    public function paginate(ProcessTemplateFiltersData $filters): LengthAwarePaginator
    {
        $query = WorkProcessTemplate::query()
            ->with('tasks')
            ->where('tenant_id', $this->currentTenant->id());

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }
        if ($filters->search !== null && $filters->search !== '') {
            $needle = '%'.mb_strtolower($filters->search).'%';
            $query->where(function ($search) use ($needle): void {
                $search->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
            });
        }

        $query->orderBy($filters->sort, $filters->direction);
        if ($filters->sort !== 'id') {
            $query->orderBy('id', $filters->direction);
        }

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }
}
