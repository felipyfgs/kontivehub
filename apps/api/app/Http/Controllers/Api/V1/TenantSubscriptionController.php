<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantSubscription;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * Assinatura/limites do tenant atual (tenant-scoped).
 */
class TenantSubscriptionController extends Controller
{
    public function show(CurrentTenant $currentTenant): JsonResponse
    {
        $tenant = $currentTenant->tenant();

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($subscription === null) {
            return response()->json([
                'message' => 'Assinatura não encontrada para o escritório atual.',
            ], 404);
        }

        return response()->json([
            'data' => $subscription->toPublicArray(),
        ]);
    }
}
