<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Integra\TenantIntegraReadinessService;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Usage\TenantUsageQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rotas tenant `/api/v1/serpro/*`.
 * Sanctum + active user + EnsureTenantContext + papéis.
 * NUNCA importa App\Services\Serpro\* nem models de contrato global.
 * tenant_id do cliente HTTP é removido pelo middleware — escopo só via CurrentTenant.
 */
class SerproTenantController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly TenantIntegraHealthService $health,
        private readonly TenantIntegraReadinessService $readiness,
        private readonly TenantUsageQueryService $usage,
    ) {}

    public function authorization(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();
        $env = $this->environment($request);
        $auth = $this->authorizations->getOrCreate($tenant, $env);

        return response()->json([
            'data' => $auth->toPublicArray(),
            'platform_health' => $this->health->forEnvironment($env),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();
        $env = $this->environment($request);

        return response()->json([
            'data' => $this->readiness->forTenant($tenant, $env),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $env = $this->environment($request);

        return response()->json([
            'data' => $this->health->forEnvironment($env),
        ]);
    }

    public function usageSummary(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();

        $year = $request->query('year');
        $month = $request->query('month');

        return response()->json([
            'data' => $this->usage->summary(
                tenantId: $tenant->id,
                year: is_numeric($year) ? (int) $year : null,
                month: is_numeric($month) ? (int) $month : null,
            ),
        ]);
    }

    public function usageEntries(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $tenant = $this->currentTenant->tenant();
        $year = $request->query('year');
        $month = $request->query('month');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $paginator = $this->usage->entries(
            tenantId: $tenant->id,
            perPage: $perPage,
            year: is_numeric($year) ? (int) $year : null,
            month: is_numeric($month) ? (int) $month : null,
            sort: $request->string('sort')->toString(),
            direction: $request->string('direction')->lower()->toString(),
        );

        return response()->json($paginator);
    }

    private function environment(Request $request): SerproEnvironment
    {
        $raw = $request->query('environment') ?? $request->input('environment');
        if (is_string($raw) && $raw !== '') {
            return SerproEnvironment::tryFrom(strtoupper($raw))
                ?? SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));
        }

        return SerproEnvironment::from((string) config('serpro.default_environment', 'TRIAL'));
    }

    private function assertAdminOrOperator(): void
    {
        $role = $this->currentTenant->role();
        if (! in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true)) {
            abort(403, 'Ação restrita a membros autorizados do escritório.');
        }
    }
}
