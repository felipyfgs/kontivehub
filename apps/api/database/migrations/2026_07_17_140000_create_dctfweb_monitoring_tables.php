<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoramento DCTFWeb: categoria/estado nas declarações, observações imutáveis
 * e colunas de projeção fail-closed (aditivas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dctfweb_declarations', function (Blueprint $table): void {
            if (! Schema::hasColumn('dctfweb_declarations', 'category')) {
                $table->string('category', 40)->default('GERAL_MENSAL')->after('period_key');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'declaration_state')) {
                $table->string('declaration_state', 40)->default('UNVERIFIED')->after('situation');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'no_movement')) {
                $table->boolean('no_movement')->nullable()->after('declaration_state');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'last_productive_consulted_at')) {
                $table->timestampTz('last_productive_consulted_at')->nullable()->after('official_at');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'calendar_verified')) {
                $table->boolean('calendar_verified')->default(false)->after('last_productive_consulted_at');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'calendar_version_code')) {
                $table->string('calendar_version_code', 60)->nullable()->after('calendar_verified');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'due_at')) {
                $table->timestampTz('due_at')->nullable()->after('calendar_version_code');
            }
            if (! Schema::hasColumn('dctfweb_declarations', 'state_reason')) {
                $table->string('state_reason', 80)->nullable()->after('due_at');
            }
        });

        // Backfill legados antes de trocar unicidade.
        if (Schema::hasColumn('dctfweb_declarations', 'category')) {
            DB::table('dctfweb_declarations')
                ->where(function ($q): void {
                    $q->whereNull('category')->orWhere('category', '');
                })
                ->update(['category' => 'GERAL_MENSAL']);
        }
        if (Schema::hasColumn('dctfweb_declarations', 'declaration_state')) {
            DB::table('dctfweb_declarations')
                ->where(function ($q): void {
                    $q->whereNull('declaration_state')->orWhere('declaration_state', '');
                })
                ->update(['declaration_state' => 'UNVERIFIED']);
        }

        $this->replaceDeclarationUniqueness();

        if (! Schema::hasTable('dctfweb_consult_observations')) {
            Schema::create('dctfweb_consult_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('declaration_id')->nullable()
                    ->constrained('dctfweb_declarations')->nullOnDelete();
                $table->foreignId('run_id')->nullable()
                    ->constrained('fiscal_monitoring_runs')->nullOnDelete();
                $table->string('category', 40)->default('GERAL_MENSAL');
                $table->string('period_key', 20);
                $table->string('ano_pa', 4);
                $table->string('mes_pa', 2);
                $table->string('outcome', 40);
                $table->string('provenance', 40)->nullable();
                $table->string('declaration_state', 40)->nullable();
                $table->boolean('productive')->default(false);
                $table->boolean('document_stored')->default(false);
                $table->string('reason', 120)->nullable();
                $table->string('sanitized_message', 255)->nullable();
                $table->timestampTz('observed_at');
                $table->json('metadata')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->index(
                    ['office_id', 'client_id', 'period_key', 'observed_at'],
                    'dctf_obs_office_client_period_obs_idx'
                );
                $table->index(
                    ['office_id', 'client_id', 'category', 'period_key'],
                    'dctf_obs_office_client_cat_period_idx'
                );
                $table->index(
                    ['office_id', 'run_id'],
                    'dctf_obs_office_run_idx'
                );
            });
        }

        Schema::table('tax_obligation_projections', function (Blueprint $table): void {
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_declaration_state')) {
                $table->string('dctfweb_declaration_state', 40)->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_last_productive_consulted_at')) {
                $table->timestampTz('dctfweb_last_productive_consulted_at')->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_last_declaration_id')) {
                $table->foreignId('dctfweb_last_declaration_id')
                    ->nullable()
                    ->constrained('dctfweb_declarations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_calendar_version_code')) {
                $table->string('dctfweb_calendar_version_code', 60)->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_calendar_verified')) {
                $table->boolean('dctfweb_calendar_verified')->default(false);
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'dctfweb_category')) {
                $table->string('dctfweb_category', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tax_obligation_projections', function (Blueprint $table): void {
            if (Schema::hasColumn('tax_obligation_projections', 'dctfweb_last_declaration_id')) {
                $table->dropConstrainedForeignId('dctfweb_last_declaration_id');
            }
            $cols = array_filter([
                Schema::hasColumn('tax_obligation_projections', 'dctfweb_declaration_state') ? 'dctfweb_declaration_state' : null,
                Schema::hasColumn('tax_obligation_projections', 'dctfweb_last_productive_consulted_at') ? 'dctfweb_last_productive_consulted_at' : null,
                Schema::hasColumn('tax_obligation_projections', 'dctfweb_calendar_version_code') ? 'dctfweb_calendar_version_code' : null,
                Schema::hasColumn('tax_obligation_projections', 'dctfweb_calendar_verified') ? 'dctfweb_calendar_verified' : null,
                Schema::hasColumn('tax_obligation_projections', 'dctfweb_category') ? 'dctfweb_category' : null,
            ]);
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::dropIfExists('dctfweb_consult_observations');

        // Restaura unicidade legada (office, client, period) se possível.
        if ($this->indexExists('dctfweb_declarations', 'dctfweb_decl_office_client_cat_period_uq')) {
            Schema::table('dctfweb_declarations', function (Blueprint $table): void {
                $table->dropUnique('dctfweb_decl_office_client_cat_period_uq');
            });
        }
        if (! $this->indexExists('dctfweb_declarations', 'dctfweb_decl_office_client_period_uq')) {
            Schema::table('dctfweb_declarations', function (Blueprint $table): void {
                $table->unique(
                    ['office_id', 'client_id', 'period_key'],
                    'dctfweb_decl_office_client_period_uq'
                );
            });
        }

        Schema::table('dctfweb_declarations', function (Blueprint $table): void {
            $cols = array_filter([
                Schema::hasColumn('dctfweb_declarations', 'category') ? 'category' : null,
                Schema::hasColumn('dctfweb_declarations', 'declaration_state') ? 'declaration_state' : null,
                Schema::hasColumn('dctfweb_declarations', 'no_movement') ? 'no_movement' : null,
                Schema::hasColumn('dctfweb_declarations', 'last_productive_consulted_at') ? 'last_productive_consulted_at' : null,
                Schema::hasColumn('dctfweb_declarations', 'calendar_verified') ? 'calendar_verified' : null,
                Schema::hasColumn('dctfweb_declarations', 'calendar_version_code') ? 'calendar_version_code' : null,
                Schema::hasColumn('dctfweb_declarations', 'due_at') ? 'due_at' : null,
                Schema::hasColumn('dctfweb_declarations', 'state_reason') ? 'state_reason' : null,
            ]);
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }

    private function replaceDeclarationUniqueness(): void
    {
        if ($this->indexExists('dctfweb_declarations', 'dctfweb_decl_office_client_period_uq')) {
            Schema::table('dctfweb_declarations', function (Blueprint $table): void {
                $table->dropUnique('dctfweb_decl_office_client_period_uq');
            });
        }

        if (! $this->indexExists('dctfweb_declarations', 'dctfweb_decl_office_client_cat_period_uq')
            && Schema::hasColumn('dctfweb_declarations', 'category')) {
            Schema::table('dctfweb_declarations', function (Blueprint $table): void {
                $table->unique(
                    ['office_id', 'client_id', 'category', 'period_key'],
                    'dctfweb_decl_office_client_cat_period_uq'
                );
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $index) {
                    return true;
                }
            }

            return false;
        }

        // MySQL / fallback
        try {
            $schema = Schema::getConnection()->getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$schema, $table, $index],
            );

            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
};
