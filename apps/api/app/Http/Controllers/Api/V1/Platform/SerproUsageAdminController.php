<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Serpro\Usage\UsageAggregationService;
use App\Services\Serpro\Usage\UsageReconciliationService;
use App\Services\Serpro\Usage\UsageReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consolidação e conciliação de consumo SERPRO (PLATFORM_ADMIN).
 * Sem conteúdo fiscal de tenants.
 */
class SerproUsageAdminController extends Controller
{
    public function __construct(
        private readonly UsageReportService $reports,
        private readonly UsageAggregationService $aggregates,
        private readonly UsageReconciliationService $reconciliation,
    ) {}

    public function consolidation(Request $request): JsonResponse
    {
        $year = $request->query('year');
        $month = $request->query('month');
        $recompute = filter_var($request->query('recompute', false), FILTER_VALIDATE_BOOL);

        $data = $this->reports->platformConsolidation(
            year: is_numeric($year) ? (int) $year : null,
            month: is_numeric($month) ? (int) $month : null,
            recompute: $recompute,
        );

        return response()->json(['data' => $data]);
    }

    public function recompute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'office_id' => ['sometimes', 'nullable', 'integer', 'exists:offices,id'],
        ]);

        $result = $this->aggregates->recomputeMonth(
            (int) $validated['year'],
            (int) $validated['month'],
            isset($validated['office_id']) ? (int) $validated['office_id'] : null,
        );

        return response()->json(['data' => $result]);
    }

    public function registerReconciliation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'official_total_cost_micros' => ['required', 'integer', 'min:0'],
            'official_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'official_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'difference_cause' => ['sometimes', 'nullable', 'string', 'max:120'],
            'recompute_aggregates' => ['sometimes', 'boolean'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.*.office_id' => ['sometimes', 'nullable', 'integer', 'exists:offices,id'],
            'adjustments.*.service_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'adjustments.*.consumption_class' => ['sometimes', 'nullable', 'string', 'max:30'],
            'adjustments.*.amount_micros' => ['required_with:adjustments', 'integer'],
            'adjustments.*.reason' => ['required_with:adjustments', 'string', 'max:120'],
            'adjustments.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $recon = $this->reconciliation->registerOfficialInvoice(
            year: (int) $validated['year'],
            month: (int) $validated['month'],
            officialTotalCostMicros: (int) $validated['official_total_cost_micros'],
            officialReference: $validated['official_reference'] ?? null,
            officialSource: $validated['official_source'] ?? null,
            notes: $validated['notes'] ?? null,
            importedByUserId: $request->user()?->id,
            adjustments: $validated['adjustments'] ?? [],
            differenceCause: $validated['difference_cause'] ?? null,
            recomputeAggregates: $validated['recompute_aggregates'] ?? true,
        );

        return response()->json([
            'data' => $recon->toPlatformArray(),
        ], 201);
    }
}
