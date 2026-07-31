<?php

namespace App\DTO\Tenant;

use App\Models\TenantMonitorSchedulePolicy;

final readonly class MonitorScheduleData
{
    public function __construct(
        public TenantMonitorSchedulePolicy $policy,
        public string $label,
    ) {}
}
