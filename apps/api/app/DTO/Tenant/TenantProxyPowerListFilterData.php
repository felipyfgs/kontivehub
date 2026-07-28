<?php

namespace App\DTO\Tenant;

final readonly class TenantProxyPowerListFilterData
{
    /** @param 'asc'|'desc' $direction */
    public function __construct(
        public ?int $clientId,
        public int $perPage,
        public string $sort,
        public string $direction,
    ) {}
}
