<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\FiscalModelAggregates;
use App\Support\FiscalDataModel\ReconciliationReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compara baseline estrutural e invariantes por agregado.
 * Exit com falha se houver divergência não aprovada (caller do comando).
 */
class FiscalModelReconcileService
{
    /**
     * @param  list<string>|null  $aggregates
     */
    public function run(?array $aggregates = null): ReconciliationReport
    {
        $targets = $aggregates ?? FiscalModelAggregates::all();
        $divergences = [];
        $matches = [];

        foreach ($targets as $aggregate) {
            if (! FiscalModelAggregates::isKnown($aggregate)) {
                $divergences[] = [
                    'aggregate' => $aggregate,
                    'metric' => 'known_aggregate',
                    'expected' => true,
                    'actual' => false,
                    'severity' => 'error',
                ];

                continue;
            }

            foreach ($this->checksFor($aggregate) as $check) {
                $entry = [
                    'aggregate' => $aggregate,
                    'metric' => $check['metric'],
                    'expected' => $check['expected'],
                    'actual' => $check['actual'],
                ];

                if ($check['expected'] === $check['actual']) {
                    $matches[] = $entry;
                } else {
                    $key = "{$aggregate}:{$check['metric']}";
                    $approved = in_array($key, config('fiscal_data_model.reconcile_approved_exceptions', []), true);
                    $divergences[] = $entry + [
                        'severity' => $approved ? 'approved_exception' : 'error',
                    ];
                }
            }
        }

        $blocking = array_filter(
            $divergences,
            static fn (array $d): bool => ($d['severity'] ?? '') === 'error',
        );

        return new ReconciliationReport(
            passed: $blocking === [],
            divergences: array_values($divergences),
            matches: $matches,
            generatedAt: now()->toIso8601String(),
        );
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function checksFor(string $aggregate): array
    {
        return match ($aggregate) {
            FiscalModelAggregates::TENANCY_CADASTRO => $this->tenancyChecks(),
            FiscalModelAggregates::DOCUMENTOS_CURSORES => $this->documentChecks(),
            FiscalModelAggregates::SERPRO => $this->serproChecks(),
            FiscalModelAggregates::MONITORAMENTO_GUIAS => $this->monitoringChecks(),
            FiscalModelAggregates::OUTBOUND => $this->outboundChecks(),
            default => [],
        };
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function tenancyChecks(): array
    {
        $checks = [];

        if (Schema::hasTable('establishments') && Schema::hasTable('clients')) {
            $mismatches = (int) DB::table('establishments as e')
                ->join('clients as c', 'c.id', '=', 'e.client_id')
                ->whereColumn('e.office_id', '<>', 'c.office_id')
                ->count();
            $checks[] = [
                'metric' => 'establishment_client_office_mismatch',
                'expected' => 0,
                'actual' => $mismatches,
            ];

            $rootMismatch = (int) DB::table('establishments as e')
                ->join('clients as c', 'c.id', '=', 'e.client_id')
                ->whereRaw('substr(e.cnpj, 1, 8) <> c.root_cnpj')
                ->count();
            $checks[] = [
                'metric' => 'establishment_root_cnpj_mismatch',
                'expected' => 0,
                'actual' => $rootMismatch,
            ];

            $liveBranches = (int) DB::table('clients')
                ->whereNotNull('matrix_client_id')
                ->count();
            $checks[] = [
                'metric' => 'live_branch_clients',
                'expected' => 0,
                'actual' => $liveBranches,
            ];
        }

        if (Schema::hasTable('client_credentials')) {
            $multiActive = (int) DB::table('client_credentials')
                ->select('client_id')
                ->where('status', 'ACTIVE')
                ->groupBy('client_id')
                ->havingRaw('count(*) > 1')
                ->get()
                ->count();
            $checks[] = [
                'metric' => 'clients_with_multiple_active_credentials',
                'expected' => 0,
                'actual' => $multiActive,
            ];
        }

        if (Schema::hasTable('fiscal_model_migration_maps')) {
            $ambiguous = (int) DB::table('fiscal_model_migration_maps')
                ->where('aggregate', FiscalModelAggregates::TENANCY_CADASTRO)
                ->where('status', 'AMBIGUOUS')
                ->count();
            $checks[] = [
                'metric' => 'ambiguous_maps',
                'expected' => 0,
                'actual' => $ambiguous,
            ];
        }

        return $checks;
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function documentChecks(): array
    {
        $checks = [];

        if (Schema::hasTable('dfe_documents')) {
            $dupHashes = (int) DB::table('dfe_documents')
                ->select('office_id', 'sha256')
                ->groupBy('office_id', 'sha256')
                ->havingRaw('count(*) > 1')
                ->get()
                ->count();
            $checks[] = [
                'metric' => 'duplicate_document_sha256_per_office',
                'expected' => 0,
                'actual' => $dupHashes,
            ];
        }

        if (Schema::hasTable('dfe_documents') && Schema::hasTable('document_acquisitions')) {
            $docs = (int) DB::table('dfe_documents')->count();
            $withAcq = (int) DB::table('dfe_documents as d')
                ->whereExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('document_acquisitions as a')
                        ->whereColumn('a.dfe_document_id', 'd.id');
                })
                ->count();
            $checks[] = [
                'metric' => 'documents_without_acquisition',
                'expected' => 0,
                'actual' => $docs - $withAcq,
            ];
        }

        return $checks;
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function serproChecks(): array
    {
        $checks = [];

        if (Schema::hasTable('serpro_api_usage_entries')) {
            $nullOffice = (int) DB::table('serpro_api_usage_entries')
                ->whereNull('office_id')
                ->count();
            $checks[] = [
                'metric' => 'usage_entries_null_office',
                'expected' => 0,
                'actual' => $nullOffice,
            ];
        }

        return $checks;
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function monitoringChecks(): array
    {
        return [];
    }

    /**
     * @return list<array{metric: string, expected: mixed, actual: mixed}>
     */
    private function outboundChecks(): array
    {
        return [];
    }
}
