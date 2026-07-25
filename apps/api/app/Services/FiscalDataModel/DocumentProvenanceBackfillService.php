<?php

namespace App\Services\FiscalDataModel;

use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gera document_acquisitions sintéticas a partir de interesses/documentos quando
 * a proveniência original não foi persistida (legado pré-aquisição).
 * Não altera bytes, sha256, vault_object_id nem datas de criação do documento.
 */
final class DocumentProvenanceBackfillService
{
    public function run(bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        if (! Schema::hasTable('dfe_documents') || ! Schema::hasTable('document_acquisitions')) {
            return new BackfillResult(
                aggregate: FiscalModelAggregates::DOCUMENTOS_CURSORES,
                dryRun: $dryRun,
                processed: 0,
                mapped: 0,
                skipped: 0,
                rejected: 0,
                ambiguous: 0,
                rejections: [['source_table' => 'document_acquisitions', 'source_id' => 0, 'reason' => 'schema_missing']],
            );
        }

        $processed = 0;
        $mapped = 0;
        $skipped = 0;
        $lastId = null;

        $docs = DB::table('dfe_documents')->orderBy('id');
        if ($officeId !== null) {
            $docs->where('office_id', $officeId);
        }

        foreach ($docs->get() as $doc) {
            $processed++;
            $lastId = (int) $doc->id;

            $hasAcq = DB::table('document_acquisitions')
                ->where('dfe_document_id', $doc->id)
                ->exists();

            if ($hasAcq) {
                $skipped++;
                $this->mapDoc((int) $doc->id, (int) $doc->office_id, 'MAPPED', 'has_acquisition', $dryRun);

                continue;
            }

            $interest = DB::table('document_interests')
                ->where('dfe_document_id', $doc->id)
                ->orderBy('id')
                ->first();

            $mapped++;
            if ($dryRun) {
                continue;
            }

            DB::table('document_acquisitions')->insert([
                'office_id' => $doc->office_id,
                'dfe_document_id' => $doc->id,
                'access_key' => $doc->access_key,
                'source' => 'LEGACY_BACKFILL',
                'channel' => $interest?->channel ?? null,
                'sha256' => $doc->sha256,
                'is_canonical' => true,
                'bytes_diverge_from_canonical' => false,
                'establishment_id' => $interest?->establishment_id ?? null,
                'nsu' => $interest?->nsu ?? null,
                'metadata' => json_encode([
                    'provenance' => 'synthetic_from_interest_or_document',
                    'limitation' => 'original_arrival_not_recorded',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $doc->created_at ?? now(),
                'updated_at' => now(),
            ]);

            $this->mapDoc((int) $doc->id, (int) $doc->office_id, 'MAPPED', 'synthetic_acquisition', false);
        }

        if (! $dryRun && $lastId !== null) {
            DB::table('fiscal_model_backfill_checkpoints')->updateOrInsert(
                [
                    'aggregate' => FiscalModelAggregates::DOCUMENTOS_CURSORES,
                    'cursor_key' => 'dfe_documents',
                ],
                [
                    'cursor_value' => (string) $lastId,
                    'office_id' => $officeId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return new BackfillResult(
            aggregate: FiscalModelAggregates::DOCUMENTOS_CURSORES,
            dryRun: $dryRun,
            processed: $processed,
            mapped: $mapped,
            skipped: $skipped,
            rejected: 0,
            ambiguous: 0,
            checkpoint: $lastId !== null ? "dfe_documents:{$lastId}" : null,
        );
    }

    private function mapDoc(int $docId, int $officeId, string $status, string $notes, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        DB::table('fiscal_model_migration_maps')->updateOrInsert(
            [
                'aggregate' => FiscalModelAggregates::DOCUMENTOS_CURSORES,
                'source_table' => 'dfe_documents',
                'source_id' => (string) $docId,
            ],
            [
                'target_table' => 'dfe_documents',
                'target_id' => (string) $docId,
                'office_id' => $officeId,
                'correlation_id' => sprintf('dfe:%d', $docId),
                'status' => $status,
                'notes_sanitized' => $notes,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}
