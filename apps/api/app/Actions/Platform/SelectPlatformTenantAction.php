<?php

namespace App\Actions\Platform;

use App\DTO\Platform\TenantSelectionData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\PlatformTenantSelectService;
use Illuminate\Http\Request;

final readonly class SelectPlatformTenantAction
{
    public function __construct(
        private PlatformTenantSelectService $selector,
    ) {}

    public function __invoke(
        User $user,
        TenantSelectionData $selection,
        Request $request,
    ): Tenant {
        return $this->selector->select($user, $selection->tenantId, $request);
    }
}
