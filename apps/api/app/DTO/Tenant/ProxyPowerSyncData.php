<?php

namespace App\DTO\Tenant;

use App\Enums\SerproEnvironment;

final readonly class ProxyPowerSyncData
{
    public function __construct(
        public SerproEnvironment $environment,
        public int $clientId,
        public int $actorUserId,
    ) {}
}
