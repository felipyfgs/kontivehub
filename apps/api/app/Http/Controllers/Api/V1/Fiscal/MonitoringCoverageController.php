<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringSurfaceRequest;
use App\Services\FiscalMonitoring\Surfaces\MonitoringCoverageService;
use Illuminate\Http\JsonResponse;

final class MonitoringCoverageController extends Controller
{
    public function __construct(
        private readonly MonitoringCoverageService $coverage,
    ) {}

    public function __invoke(
        ViewFiscalMonitoringSurfaceRequest $request,
    ): JsonResponse {
        return response()->json(['data' => $this->coverage->publicCoverage()]);
    }
}
