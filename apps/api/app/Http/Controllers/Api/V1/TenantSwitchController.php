<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Platform\SwitchTenantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SwitchRequest;
use App\Models\User;
use App\Services\Platform\TenantSwitchService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Troca explícita de tenant + listagem de memberships.
 * Fora de EnsureTenantContext para aceitar tenant_id de destino validado por membership.
 */
class TenantSwitchController extends Controller
{
    public function __construct(
        private readonly TenantSwitchService $switcher,
        private readonly SwitchTenantAction $switchTenant,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function memberships(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'current_tenant_id' => $this->currentTenant->resolve($user)?->id,
                'memberships' => $this->switcher->listMemberships($user),
            ],
        ]);
    }

    public function switch(SwitchRequest $request): JsonResponse
    {
        $tenant = ($this->switchTenant)(
            $request->actor(),
            $request->toDto(),
            $request,
        );
        $role = $this->currentTenant->role();

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'role' => $role?->value,
            ],
        ]);
    }
}
