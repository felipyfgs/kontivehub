<?php

namespace App\Services\Work;

use App\DTO\Work\WorkGenerationBatchFiltersData;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class WorkProcessGenerationBatchQuery
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /** @return LengthAwarePaginator<int, WorkProcessGenerationBatch> */
    public function paginate(
        WorkProcessTemplate $template,
        WorkGenerationBatchFiltersData $filters,
    ): LengthAwarePaginator {
        $query = WorkProcessGenerationBatch::query()
            ->where('tenant_id', $this->currentTenant->id())
            ->where('work_process_template_id', $template->id)
            ->orderByDesc('id');

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }
        if ($filters->competence !== null) {
            $query->where('competence', $filters->competence);
        }

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }
}
