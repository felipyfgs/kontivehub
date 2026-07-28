<?php

namespace App\DTO\Tenant;

use App\Models\ClientProcuracaoSync;
use App\Models\TaxProxyPower;

final readonly class TenantProxyPowerSyncResult
{
    /** @param list<TaxProxyPower> $powers */
    public function __construct(
        public array $powers,
        public ClientProcuracaoSync $sync,
    ) {}
}
