<?php

namespace App\DTO\Tenant;

final readonly class TenantIntegrationRefreshData
{
    public function __construct(
        public string $status,
        public ?string $procuradorTokenExpiresAt,
        public bool $hasProcuradorToken,
        public bool $onboardingEvaluated = false,
    ) {}
}
