<?php

namespace App\DTO\Serpro;

use App\Models\SerproQuantityUsageLimit;

final readonly class UsageLimitsUpdateResult
{
    /**
     * @param  list<array<string, mixed>>  $tenantLimits
     */
    public function __construct(
        public SerproQuantityUsageLimit $configuration,
        public array $tenantLimits,
    ) {}
}
