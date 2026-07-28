<?php

namespace App\DTO\Tenant;

final readonly class TenantOnboardingStatusData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
    ) {}
}
