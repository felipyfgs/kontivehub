<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\GetUsageConsolidationAction;
use App\Actions\Serpro\RecomputeUsageAggregatesAction;
use App\Actions\Serpro\RegisterUsageReconciliationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\GetSerproUsageConsolidationRequest;
use App\Http\Requests\Platform\RecomputeSerproUsageRequest;
use App\Http\Requests\Platform\RegisterSerproUsageReconciliationRequest;
use Illuminate\Http\JsonResponse;

/**
 * Consolidação e conciliação de consumo SERPRO (PLATFORM_ADMIN).
 * Sem conteúdo fiscal de tenants.
 */
class SerproUsageAdminController extends Controller
{
    public function __construct(
        private readonly GetUsageConsolidationAction $getConsolidation,
        private readonly RecomputeUsageAggregatesAction $recomputeAggregates,
        private readonly RegisterUsageReconciliationAction $registerReconciliation,
    ) {}

    public function consolidation(GetSerproUsageConsolidationRequest $request): JsonResponse
    {
        return response()->json([
            'data' => ($this->getConsolidation)($request->toDto()),
        ]);
    }

    public function recompute(RecomputeSerproUsageRequest $request): JsonResponse
    {
        return response()->json([
            'data' => ($this->recomputeAggregates)($request->toDto()),
        ]);
    }

    public function registerReconciliation(RegisterSerproUsageReconciliationRequest $request): JsonResponse
    {
        return response()->json([
            'data' => ($this->registerReconciliation)($request->toDto(), $request->actor()),
        ], 201);
    }
}
