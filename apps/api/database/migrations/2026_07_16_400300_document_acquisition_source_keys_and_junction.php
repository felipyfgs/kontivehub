<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência por fonte em document_acquisitions + junção aquisição–interesse.
 * Preserva unique legado (dfe_document_id, source, sha256).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPrecondition::tablesExist(
            ['document_acquisitions', 'document_interests'],
            'document_acquisition_keys',
        );

        Schema::table('document_acquisitions', function (Blueprint $table): void {
            // ADN / DistDFe: office + channel + nsu + source (quando nsu presente)
            $table->index(
                ['office_id', 'source', 'channel', 'nsu'],
                'document_acq_office_source_channel_nsu_idx',
            );
            // Import item
            $table->unique(
                ['document_import_batch_item_id', 'sha256'],
                'document_acq_batch_item_sha_unique',
            );
            // Outbound request
            $table->unique(
                ['ma_outbound_retrieval_request_id', 'sha256'],
                'document_acq_ma_request_sha_unique',
            );
        });

        MigrationPrecondition::tableMissing(
            'document_acquisition_interests',
            'document_acquisition_keys',
        );

        Schema::create('document_acquisition_interests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('document_acquisition_id')
                ->constrained('document_acquisitions')
                ->restrictOnDelete();
            $table->foreignId('document_interest_id')
                ->constrained('document_interests')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['document_acquisition_id', 'document_interest_id'],
                'document_acq_interest_unique',
            );
            $table->index(['office_id', 'document_interest_id']);
        });

        // parser_version em projeções prioritárias (sem reescrever XML)
        if (Schema::hasTable('nfse_notes') && ! Schema::hasColumn('nfse_notes', 'parser_version')) {
            Schema::table('nfse_notes', function (Blueprint $table): void {
                $table->string('parser_version', 40)->nullable();
            });
        }
        if (Schema::hasTable('nfe_documents') && ! Schema::hasColumn('nfe_documents', 'parser_version')) {
            Schema::table('nfe_documents', function (Blueprint $table): void {
                $table->string('parser_version', 40)->nullable();
            });
        }

        // Unique parcial: no máximo um is_canonical true por documento.
        // Dedupe legado (default histórico is_canonical=true) antes do índice.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY dfe_document_id
                               ORDER BY id ASC
                           ) AS rn
                    FROM document_acquisitions
                    WHERE is_canonical = true
                )
                UPDATE document_acquisitions a
                SET is_canonical = false
                FROM ranked r
                WHERE a.id = r.id AND r.rn > 1
                SQL);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX document_acq_one_canonical_per_doc
                ON document_acquisitions (dfe_document_id)
                WHERE is_canonical = true
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS document_acq_one_canonical_per_doc');
        }

        Schema::dropIfExists('document_acquisition_interests');

        Schema::table('document_acquisitions', function (Blueprint $table): void {
            $table->dropIndex('document_acq_office_source_channel_nsu_idx');
            $table->dropUnique('document_acq_batch_item_sha_unique');
            $table->dropUnique('document_acq_ma_request_sha_unique');
        });

        if (Schema::hasColumn('nfse_notes', 'parser_version')) {
            Schema::table('nfse_notes', function (Blueprint $table): void {
                $table->dropColumn('parser_version');
            });
        }
        if (Schema::hasColumn('nfe_documents', 'parser_version')) {
            Schema::table('nfe_documents', function (Blueprint $table): void {
                $table->dropColumn('parser_version');
            });
        }
    }
};
