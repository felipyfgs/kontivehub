<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformTenantSelectService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Seletor global de escritório (PLATFORM_ADMIN, modo privilegiado).
 * Fora de EnsureTenantContext; tenant_id de destino validado no serviço (não cria membership).
 */
class PlatformTenantSelectController extends Controller
{
    public function __construct(
        private readonly PlatformTenantSelectService $selector,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->selector->listEnvelope($user),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->selector->current($user),
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $tenant = $this->selector->select($user, (int) $validated['tenant_id'], $request);
        } catch (HttpException $e) {
            $code = $e->getHeaders()['X-Error-Code'] ?? null;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => is_string($code) ? $code : 'http_error',
            ], $e->getStatusCode());
        }

        return response()->json([
            'data' => [
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ],
                'tenant_role' => $this->currentTenant->role()?->value ?? TenantRole::TenantAdmin->value,
                'access_mode' => $this->currentTenant->accessMode()?->value,
                'real_tenant_role' => $this->currentTenant->realTenantRole()?->value,
                'has_real_membership' => $this->currentTenant->hasRealMembership(),
                'default_tenant_id' => $this->currentTenant->defaultTenantId($user),
                'actor_user_id' => $user->id,
            ],
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->selector->clear($user, $request);

        return response()->json([
            'data' => [
                'cleared' => true,
                'access_mode' => null,
                'tenant' => null,
            ],
        ]);
    }
}
