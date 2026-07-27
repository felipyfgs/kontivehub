<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalModuleKey;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Resources\Fiscal\FiscalModuleClientRowResource;
use App\Http\Resources\Fiscal\FiscalModuleOverviewResource;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function overview(Request $request, string $module): JsonResponse
    {
        $this->assertCanRead();
        $moduleKey = $this->resolveModule($module);
        $tenant = $this->currentTenant->tenant();
        $this->assertModuleEnabled($moduleKey, (int) $tenant->id);

        if ($rejection = $this->rejectSimplesMeiClientTenantId($request, $moduleKey)) {
            return $rejection;
        }

        // Nunca confiar em tenant_id do client (já stripado; reafirma).
        $request->query->remove('tenant_id');
        $request->request->remove('tenant_id');

        $this->assertSubmoduleAllowed($request, $moduleKey);
        $filters = ModulePortfolioFilters::fromRequest($request->query->all());
        $dto = $this->portfolio->overview($tenant, $moduleKey, $filters);

        return (new FiscalModuleOverviewResource($dto))->response();
    }

    public function clients(Request $request, string $module): JsonResponse
    {
        $this->assertCanRead();
        $moduleKey = $this->resolveModule($module);
        $tenant = $this->currentTenant->tenant();
        $this->assertModuleEnabled($moduleKey, (int) $tenant->id);

        if ($rejection = $this->rejectSimplesMeiClientTenantId($request, $moduleKey)) {
            return $rejection;
        }

        $request->query->remove('tenant_id');
        $request->request->remove('tenant_id');

        $this->assertSubmoduleAllowed($request, $moduleKey);
        $filters = ModulePortfolioFilters::fromRequest($request->query->all());
        $page = $this->portfolio->clients($tenant, $moduleKey, $filters);

        return FiscalModuleClientRowResource::collection($page)->response();
    }

    private function resolveModule(string $module): FiscalModuleKey
    {
        $key = FiscalModuleKey::tryFromRoute($module);
        if ($key === null || $key === FiscalModuleKey::Dashboard) {
            abort(404, 'Módulo fiscal desconhecido.');
        }

        return $key;
    }

    private function assertModuleEnabled(FiscalModuleKey $module, int $tenantId): void
    {
        $flag = $module->featureFlagKey();
        if ($flag === null || ! FeatureFlags::isModuleEnabled($flag, $tenantId)) {
            abort(403, 'Módulo fiscal desabilitado para este escritório.');
        }
    }

    private function assertCanRead(): void
    {
        if ($this->currentTenant->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }

    /**
     * As carteiras PGDAS-D e PGMEI rejeitam qualquer tentativa de fornecer escopo
     * de escritório, inclusive em filtros aninhados. O valor nunca é lido nem
     * usado para resolver o tenant.
     */
    private function rejectSimplesMeiClientTenantId(
        Request $request,
        FiscalModuleKey $module,
    ): ?JsonResponse {
        if ($module !== FiscalModuleKey::SimplesMei) {
            return null;
        }

        $suppliedAtTopLevel = $request->attributes->get(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) === true;
        $suppliedNested = $this->containsTenantIdKey($request->query->all())
            || $this->containsTenantIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null
                && $this->containsTenantIdKey($request->json()->all()));

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'O escritório é definido pela sessão e não pode ser informado pelo cliente.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422);
    }

    /** @param array<array-key, mixed> $values */
    private function containsTenantIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            }
            if (is_array($value) && $this->containsTenantIdKey($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A API nunca redireciona um submódulo removido para outra superfície fiscal.
     * O estado ausente continua selecionando a superfície padrão do módulo.
     */
    private function assertSubmoduleAllowed(Request $request, FiscalModuleKey $module): void
    {
        if (! $request->query->has('submodule')) {
            return;
        }

        $raw = $request->query('submodule');
        $submodule = is_string($raw) ? strtoupper(trim($raw)) : '';
        if ($submodule !== '' && in_array($submodule, $module->knownSubmodules(), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'submodule' => ['Submódulo não disponível para este módulo de monitoramento.'],
        ]);
    }
}
