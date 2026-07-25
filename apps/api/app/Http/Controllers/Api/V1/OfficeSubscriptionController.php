<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OfficeSubscription;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;

/**
 * Assinatura/limites do office atual (tenant-scoped).
 */
class OfficeSubscriptionController extends Controller
{
    public function show(CurrentOffice $currentOffice): JsonResponse
    {
        $office = $currentOffice->office();

        $subscription = OfficeSubscription::query()
            ->where('office_id', $office->id)
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
