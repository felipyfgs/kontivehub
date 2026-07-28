<?php

namespace App\DTO\Tenant;

final readonly class TenantMonitorScheduleUpdateData
{
    public function __construct(
        public int $dayOfMonth,
        public int $actorUserId,
    ) {}
}
