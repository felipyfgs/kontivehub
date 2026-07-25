<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OfficeRole;
use App\Enums\SerproEnvironment;
use App\Http\Controllers\Controller;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\TenantIntegraHealthService;
use App\Services\Integra\TenantIntegraReadinessService;
use App\Services\Usage\OfficeUsageQueryService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rotas tenant `/api/v1/serpro/*`.
 * Sanctum + active user + EnsureOfficeContext + papéis.
 * NUNCA importa App\Services\Serpro\* nem models de contrato global.
 * office_id do cliente HTTP é removido pelo middleware — escopo só via CurrentOffice.
 */
class SerproTenantController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly TenantIntegraHealthService $health,
        private readonly TenantIntegraReadinessService $readiness,
        private readonly OfficeUsageQueryService $usage,
    ) {}

    public function authorization(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $office = $this->currentOffice->office();
        $env = $this->environment($request);
        $auth = $this->authorizations->getOrCreate($office, $env);

        return response()->json([
            'data' => $auth->toPublicArray(),
            'platform_health' => $this->health->forEnvironment($env),
        ]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $office = $this->currentOffice->office();
        $env = $this->environment($request);

        return response()->json([
            'data' => $this->readiness->forOffice($office, $env),
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
        $office = $this->currentOffice->office();

        $year = $request->query('year');
        $month = $request->query('month');

        return response()->json([
            'data' => $this->usage->summary(
                officeId: $office->id,
                year: is_numeric($year) ? (int) $year : null,
                month: is_numeric($month) ? (int) $month : null,
            ),
        ]);
    }

    public function usageEntries(Request $request): JsonResponse
    {
        $this->assertAdminOrOperator();
        $office = $this->currentOffice->office();
        $year = $request->query('year');
        $month = $request->query('month');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $paginator = $this->usage->entries(
            officeId: $office->id,
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
        $role = $this->currentOffice->role();
        if (! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação restrita a ADMIN/OPERATOR do escritório.');
        }
    }
}
