<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\ReconciliationReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcilia ledger append-only vs agregados mensais (global e por office).
 */
final class SerproPeriodReconcileService
{
    public function run(?string $periodYm = null): ReconciliationReport
    {
        $periodYm ??= now()->format('Y-m');
        $divergences = [];
        $matches = [];

        if (! Schema::hasTable('serpro_api_usage_entries')) {
            return new ReconciliationReport(true, [], [], now()->toIso8601String());
        }

        $periodFilter = DB::getDriverName() === 'pgsql'
            ? "to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM') = ?"
            : "strftime('%Y-%m', occurred_at) = ?";

        $fromLedger = DB::table('serpro_api_usage_entries')
            ->select('office_id', 'consumption_class', DB::raw('sum(quantity) as qty'))
            ->whereRaw($periodFilter, [$periodYm])
            ->groupBy('office_id', 'consumption_class')
            ->get();

        $ledgerTotal = (int) $fromLedger->sum('qty');
        $matches[] = [
            'aggregate' => 'serpro',
            'metric' => 'ledger_quantity_'.$periodYm,
            'expected' => $ledgerTotal,
            'actual' => $ledgerTotal,
        ];

        if (Schema::hasTable('serpro_usage_monthly_office_aggregates')) {
            $aggTotal = (int) DB::table('serpro_usage_monthly_office_aggregates')
                ->where('period_ym', $periodYm)
                ->sum('quantity');
            // Sempre comparar: agregado zerado com ledger não-zero é defasagem real.
            if ($aggTotal !== $ledgerTotal) {
                $divergences[] = [
                    'aggregate' => 'serpro',
                    'metric' => 'office_aggregate_vs_ledger_'.$periodYm,
                    'expected' => $ledgerTotal,
                    'actual' => $aggTotal,
                    'severity' => $aggTotal === 0 && $ledgerTotal > 0 ? 'warning' : 'error',
                ];
            } else {
                $matches[] = [
                    'aggregate' => 'serpro',
                    'metric' => 'office_aggregate_matches_ledger_'.$periodYm,
                    'expected' => $ledgerTotal,
                    'actual' => $aggTotal,
                ];
            }
        }

        // Null office proibido no ledger de dados
        $nullOffice = (int) DB::table('serpro_api_usage_entries')->whereNull('office_id')->count();
        $entry = [
            'aggregate' => 'serpro',
            'metric' => 'usage_null_office',
            'expected' => 0,
            'actual' => $nullOffice,
        ];
        if ($nullOffice === 0) {
            $matches[] = $entry;
        } else {
            $divergences[] = $entry + ['severity' => 'error'];
        }

        // Idempotency keys unique
        $dupKeys = (int) DB::table('serpro_api_usage_entries')
            ->select('idempotency_key')
            ->groupBy('idempotency_key')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();
        $entry = [
            'aggregate' => 'serpro',
            'metric' => 'duplicate_idempotency_keys',
            'expected' => 0,
            'actual' => $dupKeys,
        ];
        if ($dupKeys === 0) {
            $matches[] = $entry;
        } else {
            $divergences[] = $entry + ['severity' => 'error'];
        }

        return new ReconciliationReport(
            passed: $divergences === [],
            divergences: $divergences,
            matches: $matches,
            generatedAt: now()->toIso8601String(),
        );
    }
}
