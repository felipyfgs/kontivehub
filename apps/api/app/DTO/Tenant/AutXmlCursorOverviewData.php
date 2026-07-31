<?php

namespace App\DTO\Tenant;

use App\Models\TenantDistributionRun;
use Illuminate\Support\Collection;

final readonly class AutXmlCursorOverviewData
{
    /**
     * @param  Collection<int, AutXmlCursorData>  $cursors
     * @param  Collection<int, TenantDistributionRun>  $recentRuns
     */
    public function __construct(
        public Collection $cursors,
        public AutXmlStreamData $stream,
        public Collection $recentRuns,
    ) {}
}
