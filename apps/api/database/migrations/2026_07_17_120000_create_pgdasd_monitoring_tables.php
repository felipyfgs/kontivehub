<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Colunas aditivas em projeção existente — estilo defensivo (hasColumn),
        // espelhando 2026_07_17_122000_add_pgdasd_state_to_tax_obligation_projections.
        Schema::table('tax_obligation_projections', function (Blueprint $table): void {
            if (! Schema::hasColumn('tax_obligation_projections', 'last_valid_query_at')) {
                $table->timestampTz('last_valid_query_at')->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'last_valid_run_id')) {
                $table->foreignId('last_valid_run_id')
                    ->nullable()
                    ->constrained('fiscal_monitoring_runs')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'last_valid_snapshot_id')) {
                $table->foreignId('last_valid_snapshot_id')
                    ->nullable()
                    ->constrained('fiscal_snapshots')
                    ->nullOnDelete();
            }
        });

        if (! $this->indexExists('tax_obligation_projections', 'top_office_last_valid_query_idx')
            && Schema::hasColumn('tax_obligation_projections', 'last_valid_query_at')) {
            Schema::table('tax_obligation_projections', function (Blueprint $table): void {
                $table->index(
                    ['office_id', 'last_valid_query_at'],
                    'top_office_last_valid_query_idx'
                );
            });
        }

        Schema::create('pgdasd_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('projection_id')
                ->constrained('tax_obligation_projections')
                ->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('period_key', 20);
            $table->string('logical_key', 64);
            $table->string('raw_operation_type', 80)->nullable();
            $table->string('normalized_operation_type', 40)->nullable();
            $table->string('declaration_number', 80)->nullable();
            $table->string('das_number', 80)->nullable();
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            // Serviço 13 devolve descrição textual (retida, liberada, intimada, rejeitada...).
            $table->string('malha', 80)->nullable();
            $table->boolean('payment_located')->nullable();
            $table->timestampTz('payment_observed_at')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->foreignId('source_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'logical_key'],
                'pgo_office_client_logical_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'period_key', 'kind'],
                'pgo_office_client_period_kind_idx'
            );
            $table->index(
                ['office_id', 'projection_id', 'transmitted_at'],
                'pgo_office_projection_transmitted_idx'
            );
        });

        Schema::create('pgdasd_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('projection_id')
                ->constrained('tax_obligation_projections')
                ->cascadeOnDelete();
            $table->foreignId('operation_id')
                ->nullable()
                ->constrained('pgdasd_operations')
                ->nullOnDelete();
            $table->foreignId('fiscal_evidence_artifact_id')
                ->constrained('fiscal_evidence_artifacts')
                ->restrictOnDelete();
            $table->string('declaration_number', 80)->nullable();
            $table->string('das_number', 80)->nullable();
            $table->string('kind', 40);
            $table->string('filename', 255);
            $table->string('content_type', 80)->default('application/pdf');
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'fiscal_evidence_artifact_id'],
                'pga_office_evidence_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'projection_id', 'kind'],
                'pga_office_client_projection_kind_idx'
            );
        });

        Schema::create('pgdasd_rbt12_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('projection_id')
                ->constrained('tax_obligation_projections')
                ->cascadeOnDelete();
            $table->string('source_reference_key', 64);
            $table->string('source_das_number', 80)->nullable();
            $table->string('source_declaration_number', 80)->nullable();
            $table->timestampTz('source_transmitted_at')->nullable();
            $table->unsignedBigInteger('internal_market_cents')->nullable();
            $table->unsignedBigInteger('external_market_cents')->nullable();
            $table->unsignedBigInteger('total_cents')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('extracted_at')->nullable();
            $table->text('sanitized_error')->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->foreignId('source_artifact_id')
                ->nullable()
                ->constrained('pgdasd_artifacts')
                ->nullOnDelete();
            $table->foreignId('source_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'projection_id', 'source_reference_key'],
                'pgr_office_client_projection_source_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'status'],
                'pgr_office_client_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pgdasd_rbt12_projections');
        Schema::dropIfExists('pgdasd_artifacts');
        Schema::dropIfExists('pgdasd_operations');

        $dropIndex = $this->indexExists('tax_obligation_projections', 'top_office_last_valid_query_idx');
        $dropSnapshot = Schema::hasColumn('tax_obligation_projections', 'last_valid_snapshot_id');
        $dropRun = Schema::hasColumn('tax_obligation_projections', 'last_valid_run_id');
        $dropQueryAt = Schema::hasColumn('tax_obligation_projections', 'last_valid_query_at');

        if ($dropIndex || $dropSnapshot || $dropRun || $dropQueryAt) {
            Schema::table('tax_obligation_projections', function (Blueprint $table) use (
                $dropIndex,
                $dropSnapshot,
                $dropRun,
                $dropQueryAt,
            ): void {
                if ($dropIndex) {
                    $table->dropIndex('top_office_last_valid_query_idx');
                }
                if ($dropSnapshot) {
                    $table->dropConstrainedForeignId('last_valid_snapshot_id');
                }
                if ($dropRun) {
                    $table->dropConstrainedForeignId('last_valid_run_id');
                }
                if ($dropQueryAt) {
                    $table->dropColumn('last_valid_query_at');
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        // MySQL / fallback
        try {
            $dbName = Schema::getConnection()->getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$dbName, $table, $indexName]
            );

            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
};
