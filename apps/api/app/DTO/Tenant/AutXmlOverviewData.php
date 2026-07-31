<?php

namespace App\DTO\Tenant;

use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class AutXmlOverviewData
{
    /** @param LengthAwarePaginator<int, AutXmlEnrollmentData> $enrollments */
    public function __construct(
        public ?TenantFiscalIdentity $identity,
        public ?TenantDistributionCursor $cursor,
        public AutXmlStreamData $stream,
        public LengthAwarePaginator $enrollments,
    ) {}
}
