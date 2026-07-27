<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Services\FiscalMonitoring\Surfaces\MonitoringCoverageService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;

final class MonitoringCoverageController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MonitoringCoverageService $coverage,
    ) {}

    public function __invoke(): JsonResponse
    {
        if ($this->currentTenant->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }

        return response()->json(['data' => $this->coverage->publicCoverage()]);
    }
}
