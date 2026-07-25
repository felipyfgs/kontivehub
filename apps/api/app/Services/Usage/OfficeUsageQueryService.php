<?php

namespace App\Services\Usage;

use App\Services\Serpro\Usage\UsageReportService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Fachada tenant-safe para consultas de consumo/franquia.
 * Controllers tenant usam este namespace (não App\Services\Serpro\*).
 */
final class OfficeUsageQueryService
{
    public function __construct(
        private readonly UsageReportService $reports,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(int $officeId, ?int $year = null, ?int $month = null): array
    {
        return $this->reports->tenantUsageSummary($officeId, $year, $month);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function entries(
        int $officeId,
        int $perPage = 50,
        ?int $year = null,
        ?int $month = null,
        string $sort = '',
        string $direction = '',
    ): LengthAwarePaginator {
        return $this->reports->tenantEntries($officeId, $perPage, $year, $month, $sort, $direction);
    }
}
