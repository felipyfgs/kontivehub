<?php

namespace App\DTO\Tenant;

use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class TenantAutXmlOverviewData
{
    /** @param LengthAwarePaginator<int, TenantAutXmlEnrollmentData> $enrollments */
    public function __construct(
        public ?TenantFiscalIdentity $identity,
        public ?TenantDistributionCursor $cursor,
        public TenantAutXmlStreamData $stream,
        public LengthAwarePaginator $enrollments,
    ) {}
}
