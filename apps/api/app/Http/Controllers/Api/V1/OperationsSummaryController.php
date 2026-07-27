<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationsSummaryBuilder;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

class OperationsSummaryController extends Controller
{
    public function __invoke(
        CurrentTenant $currentTenant,
        OperationsSummaryBuilder $summary,
    ): JsonResponse {
        $tenantId = $currentTenant->id();
        abort_if($tenantId === null, 403);

        return response()->json([
            'data' => $summary->build($tenantId),
        ]);
    }
}
