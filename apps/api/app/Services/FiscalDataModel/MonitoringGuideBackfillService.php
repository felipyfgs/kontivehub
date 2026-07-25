<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeia stubs de guias, competências e snapshots correntes (relatório de ambiguidades).
 */
final class MonitoringGuideBackfillService
{
    public function run(bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        $processed = 0;
        $mapped = 0;
        $skipped = 0;
        $ambiguous = 0;
        $ambiguities = [];

        if (Schema::hasTable('fiscal_guide_stubs') && Schema::hasTable('tax_guides')) {
            $q = DB::table('fiscal_guide_stubs')->orderBy('id');
            if ($officeId !== null) {
                $q->where('office_id', $officeId);
            }
            foreach ($q->get() as $stub) {
                $processed++;
                $guide = null;
                if (! empty($stub->tax_guide_id) && Schema::hasColumn('fiscal_guide_stubs', 'tax_guide_id')) {
                    $guide = DB::table('tax_guides')->where('id', $stub->tax_guide_id)->first();
                }
                if ($guide === null && ! empty($stub->client_id)) {
                    $candidates = DB::table('tax_guides')
                        ->where('office_id', $stub->office_id)
                        ->where('client_id', $stub->client_id)
                        ->limit(3)
                        ->get();
                    if ($candidates->count() === 1) {
                        $guide = $candidates->first();
                    } elseif ($candidates->count() > 1) {
                        $ambiguous++;
                        $ambiguities[] = [
                            'source_table' => 'fiscal_guide_stubs',
                            'source_id' => (int) $stub->id,
                            'reason' => 'multiple_tax_guides_for_client',
                        ];
                        if (! $dryRun) {
                            $this->map(
                                'fiscal_guide_stubs',
                                (string) $stub->id,
                                null,
                                null,
                                (int) $stub->office_id,
                                'AMBIGUOUS',
                                'multiple_tax_guides',
                            );
                        }

                        continue;
                    }
                }

                if ($guide === null) {
                    $skipped++;

                    continue;
                }

                $mapped++;
                if (! $dryRun) {
                    $this->map(
                        'fiscal_guide_stubs',
                        (string) $stub->id,
                        'tax_guides',
                        (string) $guide->id,
                        (int) $stub->office_id,
                        'MAPPED',
                        'stub_to_tax_guide',
                    );
                }
            }
        }

        // Snapshots: garantir no máximo um is_current por identidade (já enforçado por índice parcial).
        if (Schema::hasTable('fiscal_snapshots') && Schema::hasColumn('fiscal_snapshots', 'is_current')) {
            $dupGroups = DB::table('fiscal_snapshots')
                ->select('office_id', 'client_id', 'system_code', 'service_code', DB::raw('count(*) as c'))
                ->where('is_current', true)
                ->groupBy('office_id', 'client_id', 'system_code', 'service_code')
                ->havingRaw('count(*) > 1')
                ->get();
            foreach ($dupGroups as $g) {
                $ambiguous++;
                $ambiguities[] = [
                    'source_table' => 'fiscal_snapshots',
                    'source_id' => 0,
                    'reason' => sprintf(
                        'multiple_current office=%s client=%s %s/%s n=%s',
                        $g->office_id,
                        $g->client_id,
                        $g->system_code,
                        $g->service_code,
                        $g->c,
                    ),
                ];
            }
        }

        return new BackfillResult(
            aggregate: FiscalModelAggregates::MONITORAMENTO_GUIAS,
            dryRun: $dryRun,
            processed: $processed,
            mapped: $mapped,
            skipped: $skipped,
            rejected: 0,
            ambiguous: $ambiguous,
            ambiguities: $ambiguities,
        );
    }

    private function map(
        string $sourceTable,
        string $sourceId,
        ?string $targetTable,
        ?string $targetId,
        int $officeId,
        string $status,
        string $notes,
    ): void {
        DB::table('fiscal_model_migration_maps')->updateOrInsert(
            [
                'aggregate' => FiscalModelAggregates::MONITORAMENTO_GUIAS,
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
            ],
            [
                'target_table' => $targetTable,
                'target_id' => $targetId,
                'office_id' => $officeId,
                'correlation_id' => sprintf('%s:%s', $sourceTable, $sourceId),
                'status' => $status,
                'notes_sanitized' => $notes,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
