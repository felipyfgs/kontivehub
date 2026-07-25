<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Services\FiscalMonitoring\MonitoringInsightsQueryService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;

final class MonitoringInsightsController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MonitoringInsightsQueryService $insights,
    ) {}

    public function __invoke(): JsonResponse
    {
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }

        $office = $this->currentOffice->office();

        return response()->json([
            'data' => $this->insights->forOffice($office),
        ]);
    }
}
