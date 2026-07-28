<?php

namespace App\DTO\Tenant;

use App\Enums\SerproEnvironment;

final readonly class TenantSerproEligibilityData
{
    public function __construct(
        public SerproEnvironment $environment,
        public int $clientId,
        public string $solutionCode,
        public string $serviceCode,
        public string $operationCode,
        public ?string $module,
    ) {}
}
