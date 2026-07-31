<?php

namespace App\DTO\Tenant;

use App\Models\TenantDistributionCursor;

final readonly class AutXmlCursorData
{
    public function __construct(
        public TenantDistributionCursor $cursor,
        public bool $backoff,
        public bool $circuitBreakerOpen,
        public bool $circuitOpen,
    ) {}
}
