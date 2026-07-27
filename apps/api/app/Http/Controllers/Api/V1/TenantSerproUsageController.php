<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Usage\TenantUsageQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumo/franquia do escritório ativo (tenant-scoped).
 * Não expõe custo de outros tenants, orçamento global nem credenciais SERPRO.
 *
 * Não importa models/services Serpro* (architecture test de isolamento).
 */
class TenantSerproUsageController extends Controller
{
    public function __construct(
        private readonly TenantUsageQueryService $usage,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        if ($tenant === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        $year = $request->query('year');
        $month = $request->query('month');

        $data = $this->usage->summary(
            tenantId: $tenant->id,
            year: is_numeric($year) ? (int) $year : null,
            month: is_numeric($month) ? (int) $month : null,
        );

        return response()->json(['data' => $data]);
    }

    public function entries(Request $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        if ($tenant === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

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
}
