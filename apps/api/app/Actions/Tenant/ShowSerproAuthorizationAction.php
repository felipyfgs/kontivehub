<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SerproAuthorizationOverviewData;
use App\Enums\SerproEnvironment;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\CurrentTenant;

final readonly class ShowSerproAuthorizationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantSerproAuthorizationService $authorizations,
        private TenantIntegraHealthService $health,
        private SerproTenantActionableStatusService $actionableStatus,
    ) {}

    public function __invoke(
        SerproEnvironment $environment,
    ): SerproAuthorizationOverviewData {
        $tenant = $this->currentTenant->tenant();
        $authorization = $this->authorizations->getOrCreate($tenant, $environment);
        $tenantStatus = $this->actionableStatus->forTenant($tenant, $environment);

        return new SerproAuthorizationOverviewData(
            authorization: $authorization,
            platformHealth: $this->health->forEnvironment($environment),
            onboarding: $tenantStatus['onboarding'],
            actionable: $tenantStatus['actionable'],
            platformAvailable: $tenantStatus['platform_available'],
            termRepresentationStrategy: $this->authorizations
                ->representationStrategy($environment)
                ->value,
        );
    }
}
