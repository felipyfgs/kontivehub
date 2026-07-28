<?php

namespace App\Actions\Platform;

use App\Models\Tenant;
use App\Services\Activation\CreatePendingTenantService;

final readonly class ShowPlatformTenantAction
{
    public function __construct(
        private CreatePendingTenantService $createTenant,
    ) {}

    /**
     * Detalhe admin de tenant (lifecycle, profile, first_admin, activation).
     *
     * @return array<string, mixed>
     */
    public function __invoke(Tenant $tenant): array
    {
        return $this->createTenant->sanitizedTenantPayload($tenant)['tenant'];
    }
}
