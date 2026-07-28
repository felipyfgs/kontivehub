<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Models\Client;
use App\Models\Tenant;

final readonly class MonitoringModuleCommunicationReadData
{
    public function __construct(
        public Tenant $tenant,
        public Client $client,
        public string $module,
    ) {}
}
