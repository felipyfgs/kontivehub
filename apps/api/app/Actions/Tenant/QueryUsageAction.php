<?php

namespace App\Actions\Tenant;

use App\Services\Usage\TenantUsageQueryService;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class QueryUsageAction
{
    public function __construct(
        private TenantUsageQueryService $usage,
        private CurrentTenant $currentTenant,
    ) {}

    /** @return array<string, mixed> */
    public function summary(?int $year, ?int $month): array
    {
        return $this->usage->summary(
            (int) $this->currentTenant->tenant()->id,
            $year,
            $month,
        );
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function entries(
        int $perPage,
        ?int $year,
        ?int $month,
        string $sort,
        string $direction,
    ): LengthAwarePaginator {
        return $this->usage->entries(
            tenantId: (int) $this->currentTenant->tenant()->id,
            perPage: $perPage,
            year: $year,
            month: $month,
            sort: $sort,
            direction: $direction,
        );
    }
}
