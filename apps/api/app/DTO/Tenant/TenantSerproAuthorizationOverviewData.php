<?php

namespace App\DTO\Tenant;

use App\Models\TenantSerproAuthorization;

final readonly class TenantSerproAuthorizationOverviewData
{
    /**
     * @param  array<string, mixed>  $platformHealth
     * @param  array<string, mixed>  $onboarding
     * @param  list<array<string, mixed>>  $actionable
     */
    public function __construct(
        public TenantSerproAuthorization $authorization,
        public array $platformHealth,
        public array $onboarding,
        public array $actionable,
        public bool $platformAvailable,
        public string $termRepresentationStrategy,
    ) {}
}
