<?php

namespace App\Services\Work;

use App\DTO\Work\DepartmentFiltersData;
use App\Models\WorkDepartment;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class DepartmentQuery
{
    public function __construct(private CurrentTenant $currentTenant) {}

    public function paginate(
        DepartmentFiltersData $filters,
    ): LengthAwarePaginator {
        $query = WorkDepartment::query()
            ->where('tenant_id', $this->currentTenant->id())
            ->orderBy('name');

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }
}
