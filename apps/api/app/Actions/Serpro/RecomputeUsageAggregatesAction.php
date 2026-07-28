<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\UsageRecomputeData;
use App\Services\Serpro\Usage\UsageAggregationService;

final readonly class RecomputeUsageAggregatesAction
{
    public function __construct(
        private UsageAggregationService $aggregates,
    ) {}

    /** @return array{tenant_rows: int, global_rows: int} */
    public function __invoke(UsageRecomputeData $data): array
    {
        return $this->aggregates->recomputeMonth(
            $data->year,
            $data->month,
            $data->tenantId,
        );
    }
}
