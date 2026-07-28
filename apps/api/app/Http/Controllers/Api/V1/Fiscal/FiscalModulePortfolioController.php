<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\FiscalModuleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalModulePortfolioRequest;
use App\Http\Resources\Fiscal\FiscalModuleClientRowResource;
use App\Http\Resources\Fiscal\FiscalModuleOverviewResource;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;

/**
 * Read model de overview + carteira por módulo (tenant-scoped).
 * tenant_id só via CurrentTenant; query tenant_id é stripada pelo EnsureTenantContext.
 */
class FiscalModulePortfolioController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ModulePortfolioQueryService $portfolio,
    ) {}

    public function overview(
        ViewFiscalModulePortfolioRequest $request,
        string $module,
    ): JsonResponse {
        $moduleKey = $request->moduleKey();
        $tenant = $this->currentTenant->tenant();
        $this->assertModuleEnabled($moduleKey, (int) $tenant->id);
        $dto = $this->portfolio->overview($tenant, $moduleKey, $request->filters());

        return (new FiscalModuleOverviewResource($dto))->response();
    }

    public function clients(
        ViewFiscalModulePortfolioRequest $request,
        string $module,
    ): JsonResponse {
        $moduleKey = $request->moduleKey();
        $tenant = $this->currentTenant->tenant();
        $this->assertModuleEnabled($moduleKey, (int) $tenant->id);
        $page = $this->portfolio->clients($tenant, $moduleKey, $request->filters());

        return FiscalModuleClientRowResource::collection($page)->response();
    }

    private function assertModuleEnabled(FiscalModuleKey $module, int $tenantId): void
    {
        $flag = $module->featureFlagKey();
        if ($flag === null || ! FeatureFlags::isModuleEnabled($flag, $tenantId)) {
            abort(403, 'Módulo fiscal desabilitado para este escritório.');
        }
    }
}
