<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\UsagePeriodData;
use App\Services\Serpro\Usage\UsageReportService;

final readonly class GetUsageConsolidationAction
{
    public function __construct(
        private UsageReportService $reports,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(UsagePeriodData $period): array
    {
        return $this->reports->platformConsolidation(
            year: $period->year,
            month: $period->month,
        );
    }
}
