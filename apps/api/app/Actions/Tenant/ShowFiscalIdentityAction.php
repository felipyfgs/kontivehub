<?php

namespace App\Actions\Tenant;

use App\Models\TenantFiscalIdentity;
use App\Services\Certificates\TenantFiscalIdentityService;

final readonly class ShowFiscalIdentityAction
{
    public function __construct(
        private TenantFiscalIdentityService $identities,
    ) {}

    public function __invoke(): ?TenantFiscalIdentity
    {
        return $this->identities->activeForCurrentTenant();
    }
}
