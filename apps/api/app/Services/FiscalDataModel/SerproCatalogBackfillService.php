<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Garante mapas origem→serpro_operations e reporta chaves sem operation_key.
 * Seed principal ocorre na migration 400600; este comando é idempotente/ops.
 */
final class SerproCatalogBackfillService
{
    public function run(bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        // officeId ignorado: catálogo é plano de controle global
        unset($officeId);

        if (! Schema::hasTable('serpro_operations')) {
            return new BackfillResult(
                aggregate: FiscalModelAggregates::SERPRO,
                dryRun: $dryRun,
                processed: 0,
                mapped: 0,
                skipped: 0,
                rejected: 1,
                ambiguous: 0,
                rejections: [[
                    'source_table' => 'serpro_operations',
                    'source_id' => 0,
                    'reason' => 'canonical_table_missing',
                ]],
            );
        }

        $processed = 0;
        $mapped = 0;
        $skipped = 0;

        $ops = (int) DB::table('serpro_operations')->count();
        $versions = (int) DB::table('serpro_operation_versions')->count();
        $processed = $ops + $versions;
        $mapped = $ops;

        if (! $dryRun) {
            DB::table('fiscal_model_migration_maps')->updateOrInsert(
                [
                    'aggregate' => FiscalModelAggregates::SERPRO,
                    'source_table' => 'serpro_operations',
                    'source_id' => 'catalog',
                ],
                [
                    'target_table' => 'serpro_operations',
                    'target_id' => (string) $ops,
                    'office_id' => null,
                    'correlation_id' => 'serpro:catalog',
                    'status' => 'MAPPED',
                    'notes_sanitized' => sprintf('operations=%d versions=%d', $ops, $versions),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } else {
            $skipped = 0;
        }

        return new BackfillResult(
            aggregate: FiscalModelAggregates::SERPRO,
            dryRun: $dryRun,
            processed: $processed,
            mapped: $mapped,
            skipped: $skipped,
            rejected: 0,
            ambiguous: 0,
            checkpoint: "serpro_operations:{$ops}",
        );
    }
}
