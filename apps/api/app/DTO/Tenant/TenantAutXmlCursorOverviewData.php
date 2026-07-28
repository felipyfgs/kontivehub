<?php

namespace App\DTO\Tenant;

use App\Models\TenantDistributionRun;
use Illuminate\Support\Collection;

final readonly class TenantAutXmlCursorOverviewData
{
    /**
     * @param  Collection<int, TenantAutXmlCursorData>  $cursors
     * @param  Collection<int, TenantDistributionRun>  $recentRuns
     */
    public function __construct(
        public Collection $cursors,
        public TenantAutXmlStreamData $stream,
        public Collection $recentRuns,
    ) {}
}
