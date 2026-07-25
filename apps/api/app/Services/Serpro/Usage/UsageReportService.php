<?php

namespace App\Services\Serpro\Usage;

use App\Models\SerproApiUsageEntry;
use App\Models\SerproUsageMonthlyAggregate;
use App\Models\SerproUsageReconciliation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    public function tenantUsageSummary(int $officeId, ?int $year = null, ?int $month = null): array
    {
        $at = $this->periodMoment($year, $month);
        $snapshot = $this->budget->tenantSnapshot($officeId, $at);

        $aggregates = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
            ->where('office_id', $officeId)
            ->where('period_year', $snapshot['period_year'])
            ->where('period_month', $snapshot['period_month'])
            ->orderBy('service_code')
            ->get()
            ->map(fn (SerproUsageMonthlyAggregate $a) => $a->toPublicArray(includeOfficeId: false))
            ->all();

        // Sem escrita em GET: monta visão live a partir do ledger quando não há recompute.
        if ($aggregates === []) {
            $aggregates = $this->liveTenantByService(
                $officeId,
                $snapshot['period_year'],
                $snapshot['period_month'],
            );
        }

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
            // Tenant NÃO recebe global_budget / custo de outros offices.
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function tenantEntries(
        int $officeId,
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
            ->where('office_id', $officeId)
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
     * recompute=true é legado; GET não deve escrever — use recomputeAggregates() em job/POST.
     *
     * @return array<string, mixed>
     */
    public function platformConsolidation(?int $year = null, ?int $month = null, bool $recompute = false): array
    {
        $at = $this->periodMoment($year, $month);
        $y = (int) $at->year;
        $m = (int) $at->month;

        // Nunca escrever em caminho de consulta (mesmo com recompute=true no GET).
        // Operadores devem chamar recomputeAggregates explicitamente via comando/job.
        unset($recompute);

        $global = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_GLOBAL)
            ->where('period_year', $y)
            ->where('period_month', $m)
            ->orderBy('service_code')
            ->get()
            ->map(fn (SerproUsageMonthlyAggregate $a) => $a->toPublicArray(includeOfficeId: false))
            ->all();

        $byTenant = SerproUsageMonthlyAggregate::query()
            ->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
            ->where('period_year', $y)
            ->where('period_month', $m)
            ->orderBy('office_id')
            ->get()
            ->groupBy('office_id')
            ->map(function ($rows, $officeId) {
                $qty = $rows->sum('total_quantity');
                $cost = $rows->sum('total_estimated_cost_micros');
                $entries = $rows->sum('entry_count');

                return [
                    'office_id' => (int) $officeId,
                    'entry_count' => $entries,
                    'total_quantity' => $qty,
                    'total_estimated_cost_micros' => $cost,
                ];
            })
            ->values()
            ->all();

        // Live fallback se não há agregados persistidos
        if ($global === [] && $byTenant === []) {
            $start = Carbon::create($y, $m, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $live = $this->aggregates->liveTotals($start, $end);
            $byTenant = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->whereBetween('occurred_at', [$start, $end])
                ->select([
                    'office_id',
                    DB::raw('COUNT(*) as entry_count'),
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(estimated_cost_micros), 0) as total_estimated_cost_micros'),
                ])
                ->groupBy('office_id')
                ->get()
                ->map(fn ($r) => [
                    'office_id' => (int) $r->office_id,
                    'entry_count' => (int) $r->entry_count,
                    'total_quantity' => (int) $r->total_quantity,
                    'total_estimated_cost_micros' => (int) $r->total_estimated_cost_micros,
                ])
                ->all();
            $global = [[
                'scope' => 'GLOBAL',
                'total_quantity' => $live['global_quantity'],
                'total_estimated_cost_micros' => $live['global_micros'],
            ]];
        }

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
            'global_monthly_budget' => config('serpro_usage.global_monthly_budget'),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function liveTenantByService(int $officeId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereBetween('occurred_at', [$start, $end])
            ->select([
                'service_code',
                'consumption_class',
                DB::raw('COUNT(*) as entry_count'),
                DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(estimated_cost_micros), 0) as total_estimated_cost_micros'),
            ])
            ->groupBy(['service_code', 'consumption_class'])
            ->orderBy('service_code')
            ->get()
            ->map(fn ($r) => [
                'service_code' => $r->service_code,
                'consumption_class' => $r->consumption_class,
                'entry_count' => (int) $r->entry_count,
                'total_quantity' => (int) $r->total_quantity,
                'total_estimated_cost_micros' => (int) $r->total_estimated_cost_micros,
            ])
            ->all();
    }

    private function periodMoment(?int $year, ?int $month): Carbon
    {
        if ($year !== null && $month !== null) {
            return Carbon::create($year, $month, 15)->startOfDay();
        }

        return now();
    }
}
