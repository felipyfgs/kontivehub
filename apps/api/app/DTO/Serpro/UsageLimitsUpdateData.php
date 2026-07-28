<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;

final readonly class UsageLimitsUpdateData
{
    /**
     * @param  list<TenantQuantityLimitData>  $tenantLimits
     */
    public function __construct(
        public SerproEnvironment $environment,
        public int $cycleStartDay,
        public int $alertPercent,
        public ?int $globalLimitQuantity,
        public array $tenantLimits,
    ) {}

    /** @return list<array{tenant_id: int, limit_quantity: int|null}> */
    public function tenantLimitPayloads(): array
    {
        return array_map(
            static fn (TenantQuantityLimitData $limit): array => $limit->toArray(),
            $this->tenantLimits,
        );
    }
}
