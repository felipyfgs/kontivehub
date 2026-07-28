<?php

namespace App\Services\Communication\Flows;

use App\DTO\Communication\CommunicationFlowRunFiltersData;
use App\Enums\Communication\FlowRunStatus;
use App\Models\CommunicationFlowRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CommunicationFlowRunQuery
{
    /** @return LengthAwarePaginator<int, CommunicationFlowRun> */
    public function paginate(
        CommunicationFlowRunFiltersData $filters,
    ): LengthAwarePaginator {
        $query = CommunicationFlowRun::query()->orderByDesc('id');

        if ($filters->flowId !== null) {
            $query->where('flow_id', $filters->flowId);
        }
        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }
        if ($filters->activeOnly) {
            $query->whereIn('status', FlowRunStatus::nonTerminalValues());
        }

        return $query->paginate(
            perPage: $filters->perPage,
            page: $filters->page,
        );
    }
}
