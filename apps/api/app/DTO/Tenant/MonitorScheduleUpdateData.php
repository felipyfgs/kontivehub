<?php

namespace App\DTO\Tenant;

final readonly class MonitorScheduleUpdateData
{
    public function __construct(
        public int $dayOfMonth,
        public int $actorUserId,
    ) {}
}
