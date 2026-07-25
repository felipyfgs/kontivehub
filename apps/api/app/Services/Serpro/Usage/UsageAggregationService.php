<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproConsumptionClass;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproBillingCycle;
use App\Models\SerproUsageMonthlyAggregate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agregações por ciclo 21–20 e por mês calendário, recomputáveis a partir do ledger.
 * Totais tenant e global isolados. Escritas apenas via recompute explícito (não em GET).
 */
final class UsageAggregationService
{
    public const PERIOD_CALENDAR = 'CALENDAR_MONTH';

    public const PERIOD_BILLING_CYCLE = 'BILLING_CYCLE_21_20';

    public function __construct(
        private readonly BillingCycleResolver $cycles,
    ) {}

    /**
     * Recomputa agregados do mês calendário a partir de serpro_api_usage_entries.
     *
     * @return array{tenant_rows: int, global_rows: int}
     */
    public function recomputeMonth(int $year, int $month, ?int $officeId = null): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->recomputeWindow(
            start: $start,
            end: $end,
            periodYear: $year,
            periodMonth: $month,
            cycleCode: null,
            periodKind: self::PERIOD_CALENDAR,
            officeId: $officeId,
        );
    }

    /**
     * Recomputa agregados do ciclo contratual 21–20.
     *
     * @return array{tenant_rows: int, global_rows: int, cycle_code: string}
     */
    public function recomputeBillingCycle(Carbon|string|null $at = null, ?int $officeId = null): array
    {
        $resolved = $this->cycles->resolve($at);
        $this->cycles->ensurePersisted($at);
        $calendar = $this->cycles->calendarMonthOfCycleEnd($at);

        $result = $this->recomputeWindow(
            start: $resolved['period_start'],
            end: $resolved['period_end'],
            periodYear: $calendar['year'],
            periodMonth: $calendar['month'],
            cycleCode: $resolved['cycle_code'],
            periodKind: self::PERIOD_BILLING_CYCLE,
            officeId: $officeId,
        );

        return $result + ['cycle_code' => $resolved['cycle_code']];
    }

    /**
     * Soma estimada interna do mês calendário (todas as offices) a partir do ledger.
     */
    public function internalEstimatedTotalMicros(int $year, int $month): int
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return $this->sumEstimated($start, $end, officeId: null);
    }

    public function internalEstimatedTotalMicrosForCycle(string $cycleCode): int
    {
        $cycle = $this->cycles->resolve(); // fallback
        // Preferência: linha persistida
        $row = SerproBillingCycle::query()->where('cycle_code', $cycleCode)->first();
        if ($row !== null) {
            return $this->sumEstimated(
                Carbon::parse($row->period_start)->startOfDay(),
                Carbon::parse($row->period_end)->endOfDay(),
                officeId: null,
            );
        }

        return $this->sumEstimated($cycle['period_start'], $cycle['period_end'], officeId: null);
    }

    /**
     * Totais isolados tenant vs global (leitura live, sem escrita).
     *
     * @return array{tenant_micros: int, global_micros: int, tenant_quantity: int, global_quantity: int}
     */
    public function liveTotals(Carbon $start, Carbon $end, ?int $officeId = null): array
    {
        $globalMicros = $this->sumEstimated($start, $end, null);
        $globalQty = $this->sumQuantity($start, $end, null);
        $tenantMicros = $officeId !== null ? $this->sumEstimated($start, $end, $officeId) : 0;
        $tenantQty = $officeId !== null ? $this->sumQuantity($start, $end, $officeId) : 0;

        return [
            'tenant_micros' => $tenantMicros,
            'global_micros' => $globalMicros,
            'tenant_quantity' => $tenantQty,
            'global_quantity' => $globalQty,
        ];
    }

    public function aggregateKey(
        string $scope,
        ?int $officeId,
        int $year,
        int $month,
        ?string $system,
        ?string $service,
        ?string $class,
        ?string $cycleCode = null,
        string $periodKind = self::PERIOD_CALENDAR,
    ): string {
        return implode(':', [
            $scope,
            $officeId === null ? '-' : (string) $officeId,
            (string) $year,
            (string) $month,
            $periodKind,
            $cycleCode ?? '-',
            $system ?? '-',
            $service ?? '-',
            $class ?? '-',
        ]);
    }

    /**
     * @return array{tenant_rows: int, global_rows: int}
     */
    private function recomputeWindow(
        Carbon $start,
        Carbon $end,
        int $periodYear,
        int $periodMonth,
        ?string $cycleCode,
        string $periodKind,
        ?int $officeId,
    ): array {
        $query = SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->whereBetween('occurred_at', [$start, $end]);

        if ($officeId !== null) {
            $query->where('office_id', $officeId);
        }

        /** @var Collection<int, object> $groups */
        $groups = $query
            ->select([
                'office_id',
                'system_code',
                'service_code',
                'consumption_class',
                DB::raw('COUNT(*) as entry_count'),
                DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(estimated_cost_micros), 0) as total_estimated_cost_micros'),
                DB::raw("SUM(CASE WHEN consumption_class = 'DESCONHECIDA' THEN 1 ELSE 0 END) as unknown_class_count"),
                DB::raw('SUM(CASE WHEN is_billable_attempt = 1 THEN 1 ELSE 0 END) as billable_attempt_count'),
            ])
            ->groupBy(['office_id', 'system_code', 'service_code', 'consumption_class'])
            ->get();

        $now = now();
        $tenantRows = 0;

        $delete = SerproUsageMonthlyAggregate::query()
            ->where('period_year', $periodYear)
            ->where('period_month', $periodMonth);

        if (Schema::hasColumn('serpro_usage_monthly_aggregates', 'period_kind')) {
            $delete->where('period_kind', $periodKind);
            if ($cycleCode !== null) {
                $delete->where('cycle_code', $cycleCode);
            } else {
                $delete->where(function ($q): void {
                    $q->whereNull('cycle_code')->orWhere('cycle_code', '');
                });
            }
        }

        if ($officeId !== null) {
            $delete->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
                ->where('office_id', $officeId);
        } else {
            $delete->where(function ($q): void {
                $q->where('scope', SerproUsageMonthlyAggregate::SCOPE_TENANT)
                    ->orWhere('scope', SerproUsageMonthlyAggregate::SCOPE_GLOBAL);
            });
        }
        $delete->delete();

        foreach ($groups as $row) {
            $classValue = $this->classValue($row->consumption_class);
            $key = $this->aggregateKey(
                SerproUsageMonthlyAggregate::SCOPE_TENANT,
                (int) $row->office_id,
                $periodYear,
                $periodMonth,
                $row->system_code,
                $row->service_code,
                $classValue,
                $cycleCode,
                $periodKind,
            );

            $payload = [
                'scope' => SerproUsageMonthlyAggregate::SCOPE_TENANT,
                'office_id' => (int) $row->office_id,
                'period_year' => $periodYear,
                'period_month' => $periodMonth,
                'system_code' => $row->system_code,
                'service_code' => $row->service_code,
                'consumption_class' => $classValue,
                'aggregate_key' => $key,
                'entry_count' => (int) $row->entry_count,
                'total_quantity' => (int) $row->total_quantity,
                'total_estimated_cost_micros' => (int) $row->total_estimated_cost_micros,
                'unknown_class_count' => (int) $row->unknown_class_count,
                'billable_attempt_count' => (int) $row->billable_attempt_count,
                'recomputed_at' => $now,
            ];
            if (Schema::hasColumn('serpro_usage_monthly_aggregates', 'cycle_code')) {
                $payload['cycle_code'] = $cycleCode;
                $payload['period_kind'] = $periodKind;
            }

            SerproUsageMonthlyAggregate::query()->create($payload);
            $tenantRows++;
        }

        $globalRows = 0;
        if ($officeId === null) {
            $globalGroups = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->whereBetween('occurred_at', [$start, $end])
                ->select([
                    'system_code',
                    'service_code',
                    'consumption_class',
                    DB::raw('COUNT(*) as entry_count'),
                    DB::raw('COALESCE(SUM(quantity), 0) as total_quantity'),
                    DB::raw('COALESCE(SUM(estimated_cost_micros), 0) as total_estimated_cost_micros'),
                    DB::raw("SUM(CASE WHEN consumption_class = 'DESCONHECIDA' THEN 1 ELSE 0 END) as unknown_class_count"),
                    DB::raw('SUM(CASE WHEN is_billable_attempt = 1 THEN 1 ELSE 0 END) as billable_attempt_count'),
                ])
                ->groupBy(['system_code', 'service_code', 'consumption_class'])
                ->get();

            foreach ($globalGroups as $row) {
                $classValue = $this->classValue($row->consumption_class);
                $key = $this->aggregateKey(
                    SerproUsageMonthlyAggregate::SCOPE_GLOBAL,
                    null,
                    $periodYear,
                    $periodMonth,
                    $row->system_code,
                    $row->service_code,
                    $classValue,
                    $cycleCode,
                    $periodKind,
                );

                $payload = [
                    'scope' => SerproUsageMonthlyAggregate::SCOPE_GLOBAL,
                    'office_id' => null,
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'system_code' => $row->system_code,
                    'service_code' => $row->service_code,
                    'consumption_class' => $classValue,
                    'aggregate_key' => $key,
                    'entry_count' => (int) $row->entry_count,
                    'total_quantity' => (int) $row->total_quantity,
                    'total_estimated_cost_micros' => (int) $row->total_estimated_cost_micros,
                    'unknown_class_count' => (int) $row->unknown_class_count,
                    'billable_attempt_count' => (int) $row->billable_attempt_count,
                    'recomputed_at' => $now,
                ];
                if (Schema::hasColumn('serpro_usage_monthly_aggregates', 'cycle_code')) {
                    $payload['cycle_code'] = $cycleCode;
                    $payload['period_kind'] = $periodKind;
                }
                SerproUsageMonthlyAggregate::query()->create($payload);
                $globalRows++;
            }

            $total = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->whereBetween('occurred_at', [$start, $end])
                ->selectRaw('COUNT(*) as entry_count')
                ->selectRaw('COALESCE(SUM(quantity), 0) as total_quantity')
                ->selectRaw('COALESCE(SUM(estimated_cost_micros), 0) as total_estimated_cost_micros')
                ->selectRaw("SUM(CASE WHEN consumption_class = 'DESCONHECIDA' THEN 1 ELSE 0 END) as unknown_class_count")
                ->selectRaw('SUM(CASE WHEN is_billable_attempt = 1 THEN 1 ELSE 0 END) as billable_attempt_count')
                ->first();

            if ($total && (int) $total->entry_count > 0) {
                $key = $this->aggregateKey(
                    SerproUsageMonthlyAggregate::SCOPE_GLOBAL,
                    null,
                    $periodYear,
                    $periodMonth,
                    null,
                    null,
                    null,
                    $cycleCode,
                    $periodKind,
                );
                $payload = [
                    'scope' => SerproUsageMonthlyAggregate::SCOPE_GLOBAL,
                    'office_id' => null,
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'system_code' => null,
                    'service_code' => null,
                    'consumption_class' => null,
                    'aggregate_key' => $key,
                    'entry_count' => (int) $total->entry_count,
                    'total_quantity' => (int) $total->total_quantity,
                    'total_estimated_cost_micros' => (int) $total->total_estimated_cost_micros,
                    'unknown_class_count' => (int) $total->unknown_class_count,
                    'billable_attempt_count' => (int) $total->billable_attempt_count,
                    'recomputed_at' => $now,
                ];
                if (Schema::hasColumn('serpro_usage_monthly_aggregates', 'cycle_code')) {
                    $payload['cycle_code'] = $cycleCode;
                    $payload['period_kind'] = $periodKind;
                }
                SerproUsageMonthlyAggregate::query()->create($payload);
                $globalRows++;
            }
        }

        return ['tenant_rows' => $tenantRows, 'global_rows' => $globalRows];
    }

    private function sumEstimated(Carbon $start, Carbon $end, ?int $officeId): int
    {
        $q = SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->whereBetween('occurred_at', [$start, $end])
            ->whereNotNull('estimated_cost_micros');

        if ($officeId !== null) {
            $q->where('office_id', $officeId);
        }

        return (int) $q->sum('estimated_cost_micros');
    }

    private function sumQuantity(Carbon $start, Carbon $end, ?int $officeId): int
    {
        $q = SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->whereBetween('occurred_at', [$start, $end]);

        if ($officeId !== null) {
            $q->where('office_id', $officeId);
        }

        return (int) $q->sum('quantity');
    }

    private function classValue(mixed $class): ?string
    {
        if ($class instanceof SerproConsumptionClass) {
            return $class->value;
        }
        if ($class instanceof \BackedEnum) {
            return (string) $class->value;
        }
        if ($class === null || $class === '') {
            return null;
        }

        return (string) $class;
    }
}
