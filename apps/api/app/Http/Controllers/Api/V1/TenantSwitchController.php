<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\TenantSwitchService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Troca explícita de tenant + listagem de memberships.
 * Fora de EnsureOfficeContext para aceitar office_id de destino validado por membership.
 */
class TenantSwitchController extends Controller
{
    public function __construct(
        private readonly TenantSwitchService $switcher,
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function memberships(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'current_office_id' => $this->currentOffice->resolve($user)?->id,
                'memberships' => $this->switcher->listMemberships($user),
            ],
        ]);
    }

    public function switch(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'office_id' => ['required', 'integer', 'min:1'],
        ]);

        $office = $this->switcher->switchTo($user, (int) $validated['office_id'], $request);
        $role = $this->currentOffice->role();

        return response()->json([
            'data' => [
                'office' => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'slug' => $office->slug,
                ],
                'role' => $role?->value,
            ],
        ]);
    }
}
