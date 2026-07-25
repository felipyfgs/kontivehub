<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Usage\OfficeUsageQueryService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consumo/franquia do escritório ativo (tenant-scoped).
 * Não expõe custo de outros tenants, orçamento global nem credenciais SERPRO.
 *
 * Não importa models/services Serpro* (architecture test de isolamento).
 */
class OfficeSerproUsageController extends Controller
{
    public function __construct(
        private readonly OfficeUsageQueryService $usage,
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $office = $this->currentOffice->office();
        if ($office === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

        $year = $request->query('year');
        $month = $request->query('month');

        $data = $this->usage->summary(
            officeId: $office->id,
            year: is_numeric($year) ? (int) $year : null,
            month: is_numeric($month) ? (int) $month : null,
        );

        return response()->json(['data' => $data]);
    }

    public function entries(Request $request): JsonResponse
    {
        $office = $this->currentOffice->office();
        if ($office === null) {
            return response()->json(['message' => 'Usuário sem escritório ativo.'], 403);
        }

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
}
