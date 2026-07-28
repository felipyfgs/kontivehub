<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringSurfaceRequest;
use App\Services\FiscalMonitoring\MonitoringInsightsQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

final class MonitoringInsightsController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MonitoringInsightsQueryService $insights,
    ) {}

    public function __invoke(
        ViewFiscalMonitoringSurfaceRequest $request,
    ): JsonResponse {
        $tenant = $this->currentTenant->tenant();

        return response()->json([
            'data' => $this->insights->forTenant($tenant),
        ]);
    }
}
