<?php

namespace App\Actions\Platform;

use App\DTO\Platform\TenantSelectionData;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantSwitchService;
use Illuminate\Http\Request;

final readonly class SwitchTenantAction
{
    public function __construct(
        private TenantSwitchService $switcher,
    ) {}

    public function __invoke(
        User $user,
        TenantSelectionData $selection,
        Request $request,
    ): Tenant {
        return $this->switcher->switchTo($user, $selection->tenantId, $request);
    }
}
