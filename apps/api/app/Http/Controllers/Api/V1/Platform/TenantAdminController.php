<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\UpdateTenantSubscriptionAction;
use App\Exceptions\TenantSubscriptionUpdateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ListPlatformTenantsRequest;
use App\Http\Requests\Platform\UpdateTenantSubscriptionRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Administração global sanitizada de tenants (PLATFORM_ADMIN).
 * NÃO expõe conteúdo fiscal, mensagens, relatórios ou evidências.
 */
class TenantAdminController extends Controller
{
    public function __construct(
        private readonly UpdateTenantSubscriptionAction $updateSubscription,
    ) {}

    public function index(ListPlatformTenantsRequest $request): JsonResponse
    {
        $query = Tenant::query()
            ->with('subscription')
            ->orderBy('id');

        if ($status = $request->status()) {
            $query->whereHas('subscription', fn ($q) => $q->where('status', $status->value));
        }

        $tenants = $query->get()->map(fn (Tenant $tenant) => $this->sanitizeTenant($tenant));

        return response()->json([
            'data' => $tenants,
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load('subscription');

        return response()->json([
            'data' => $this->sanitizeTenant($tenant),
        ]);
    }

    public function updateSubscription(
        UpdateTenantSubscriptionRequest $request,
        Tenant $tenant,
    ): JsonResponse {
        try {
            ($this->updateSubscription)(
                $tenant,
                $request->toDto(),
                $request->actor(),
            );
        } catch (TenantSubscriptionUpdateException $error) {
            return response()->json([
                'message' => $error->safeMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $this->sanitizeTenant($tenant->fresh(['subscription'])),
        ]);
    }

    /**
     * Metadados comerciais e saúde sanitizada — zero conteúdo fiscal.
     *
     * @return array<string, mixed>
     */
    private function sanitizeTenant(Tenant $tenant): array
    {
        $subscription = $tenant->subscription;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'is_active' => $tenant->is_active,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'subscription' => $subscription?->toSanitizedAdminArray(),
            // Contagens agregadas não-fiscais (sem listar clientes/docs)
            'memberships_count' => $tenant->memberships()->count(),
        ];
    }
}
