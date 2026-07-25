<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Services\FiscalMonitoring\Surfaces\MonitoringCoverageService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;

final class MonitoringCoverageController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MonitoringCoverageService $coverage,
    ) {}

    public function __invoke(): JsonResponse
    {
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }

        return response()->json(['data' => $this->coverage->publicCoverage()]);
    }
}
