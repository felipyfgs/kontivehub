<?php

namespace App\Services\Serpro\Usage;

use App\Models\SerproApiUsageEntry;
use App\Models\SerproUsageMonthlyAggregate;
use App\Models\SerproUsageReconciliation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Consultas de consumo para tenant e plataforma.
 * Controllers tenant usam este service (sem importar models Serpro*).
 *
 * GET paths: somente leitura — sem recompute/escrita de agregados.
 */
final class UsageReportService
{
    public function __construct(
        private readonly UsageBudgetGate $budget,
        private readonly UsageShadowPolicy $shadow,
        private readonly UsageAggregationService $aggregates,
        private readonly BillingCycleResolver $cycles,
    ) {}

    /**
     * Painel de uso/franquia do escritório ativo.
     *
     * @return array<string, mixed>
     */
    public function tenantUsageSummary(int $tenantId, ?int $year = null, ?int $month = null): array
    {
        $at = $this->periodMoment($year, $month);
        $snapshot = $this->budget->tenantSnapshot($tenantId, $at);

        $aggregates = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
            ->where('tenant_id', $tenantId)
            ->where('period_year', $snapshot['period_year'])
            ->where('period_month', $snapshot['period_month'])
            ->orderBy('service_code')
            ->get()
            ->map(fn (SerproUsageMonthlyAggregate $a) => $a->toPublicArray(includeTenantId: false))
            ->all();

        $cycle = $this->cycles->resolve($at);

        return [
            'summary' => $snapshot,
            'by_service' => $aggregates,
            'billing_cycle' => [
                'cycle_code' => $cycle['cycle_code'],
                'period_start' => $cycle['period_start']->toDateString(),
                'period_end' => $cycle['period_end']->toDateString(),
                'kind' => $cycle['kind'],
            ],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function tenantEntries(
        int $tenantId,
        int $perPage = 50,
        ?int $year = null,
        ?int $month = null,
        string $sort = '',
        string $direction = '',
    ): LengthAwarePaginator {
        $sortColumn = match ($sort) {
            'quantity' => 'quantity',
            'result' => 'result',
            'client_id' => 'client_id',
            'id' => 'id',
            default => 'occurred_at',
        };
        $sortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $query = SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy($sortColumn, $sortDirection);
        if ($sortColumn !== 'id') {
            $query->orderBy('id', $sortDirection);
        }

        if ($year !== null && $month !== null) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('occurred_at', [$start, $end]);
        }

        return $query->paginate($perPage)->through(
            fn (SerproApiUsageEntry $e) => $e->toTenantArray()
        );
    }

    /**
     * Consolidação global (PLATFORM_ADMIN).
     *
     * @return array<string, mixed>
     */
    public function platformConsolidation(?int $year = null, ?int $month = null): array
    {
        $at = $this->periodMoment($year, $month);
        $y = (int) $at->year;
        $m = (int) $at->month;

        $global = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_GLOBAL)
            ->where('period_year', $y)
            ->where('period_month', $m)
            ->orderBy('service_code')
            ->get()
            ->map(fn (SerproUsageMonthlyAggregate $a) => $a->toPublicArray(includeTenantId: false))
            ->all();

        $byTenant = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
            ->where('period_year', $y)
            ->where('period_month', $m)
            ->orderBy('tenant_id')
            ->get()
            ->groupBy('tenant_id')
            ->map(function ($rows, $tenantId) {
                $qty = $rows->sum('total_quantity');
                $cost = $rows->sum('total_estimated_cost_micros');
                $entries = $rows->sum('entry_count');

                return [
                    'tenant_id' => (int) $tenantId,
                    'entry_count' => $entries,
                    'total_quantity' => $qty,
                    'total_estimated_cost_micros' => $cost,
                ];
            })
            ->values()
            ->all();

        $reconciliations = SerproUsageReconciliation::query()
            ->where('period_year', $y)
            ->where('period_month', $m)
            ->with('adjustments')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SerproUsageReconciliation $r) => $r->toPlatformArray())
            ->all();

        $cycle = $this->cycles->resolve($at);

        return [
            'period_year' => $y,
            'period_month' => $m,
            'billing_cycle' => [
                'cycle_code' => $cycle['cycle_code'],
                'period_start' => $cycle['period_start']->toDateString(),
                'period_end' => $cycle['period_end']->toDateString(),
            ],
            'policy' => $this->shadow->snapshot(),
            'global_aggregates' => $global,
            'by_tenant' => $byTenant,
            'internal_estimated_total_micros' => $this->aggregates->internalEstimatedTotalMicros($y, $m),
            'reconciliations' => $reconciliations,
        ];
    }

    /**
     * Escrita explícita de agregados (job / POST admin — não GET).
     *
     * @return array{tenant_rows: int, global_rows: int}
     */
    public function recomputeAggregates(int $year, int $month, bool $billingCycle = false): array
    {
        if ($billingCycle) {
            return $this->aggregates->recomputeBillingCycle(Carbon::create($year, $month, 15));
        }

        return $this->aggregates->recomputeMonth($year, $month);
    }

    private function periodMoment(?int $year, ?int $month): Carbon
    {
        if ($year !== null && $month !== null) {
            return Carbon::create($year, $month, 15)->startOfDay();
        }

        return now();
    }
}
