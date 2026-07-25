<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança produtiva: elegibilidade de tabela de preços, ciclo 21–20,
 * estado durável de breaker e proteção de ledger no offboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serpro_price_versions')) {
            Schema::table('serpro_price_versions', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_price_versions', 'source_url')) {
                    $table->string('source_url', 500)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('serpro_price_versions', 'source_hash')) {
                    $table->string('source_hash', 64)->nullable()->after('source_url');
                }
                if (! Schema::hasColumn('serpro_price_versions', 'source_revision')) {
                    $table->string('source_revision', 80)->nullable()->after('source_hash');
                }
                if (! Schema::hasColumn('serpro_price_versions', 'eligibility')) {
                    $table->string('eligibility', 30)->default('SHADOW')->after('source_revision');
                }
                if (! Schema::hasColumn('serpro_price_versions', 'authorizes_production')) {
                    $table->boolean('authorizes_production')->default(false)->after('eligibility');
                }
                if (! Schema::hasColumn('serpro_price_versions', 'billing_cycle_kind')) {
                    $table->string('billing_cycle_kind', 20)->default('D21_D20')->after('authorizes_production');
                }
            });

            // Shadow seed da migration 220000 não autoriza produção.
            DB::table('serpro_price_versions')
                ->where('version_code', 'v1-shadow-2026')
                ->update([
                    'eligibility' => 'SHADOW',
                    'authorizes_production' => false,
                    'source_revision' => 'migration-220000-shadow',
                    'updated_at' => now(),
                ]);
        }

        if (! Schema::hasTable('serpro_circuit_breaker_states')) {
            Schema::create('serpro_circuit_breaker_states', function (Blueprint $table): void {
                $table->id();
                $table->string('scope_key', 120)->unique();
                $table->string('dependency', 40)->default('SERPRO');
                $table->string('solution_code', 40)->nullable();
                $table->string('state', 20)->default('closed');
                $table->unsignedInteger('failures')->default(0);
                $table->unsignedInteger('half_open_probes')->default(0);
                $table->timestampTz('open_until')->nullable();
                $table->string('last_reason', 200)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['dependency', 'solution_code']);
                $table->index(['state', 'open_until']);
            });
        }

        if (! Schema::hasTable('serpro_billing_invoice_lines')) {
            Schema::create('serpro_billing_invoice_lines', function (Blueprint $table): void {
                $table->id();
                $table->string('cycle_code', 40);
                $table->foreignId('reconciliation_id')
                    ->nullable()
                    ->constrained('serpro_usage_reconciliations')
                    ->nullOnDelete();
                $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
                $table->string('functional_route', 40)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('request_tag', 32)->nullable();
                $table->string('system_code', 40)->nullable();
                $table->string('service_code', 80)->nullable();
                $table->string('operation_code', 80)->nullable();
                $table->string('consumption_class', 30)->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedBigInteger('official_cost_micros')->default(0);
                $table->unsignedBigInteger('internal_cost_micros')->nullable();
                $table->bigInteger('difference_micros')->default(0);
                $table->string('line_status', 30)->default('IMPORTED');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['cycle_code', 'office_id'], 'serpro_bill_line_cycle_office_idx');
                $table->index(['request_tag']);
                $table->index(['functional_route', 'http_status']);
            });
        }

        if (Schema::hasTable('serpro_usage_monthly_aggregates')) {
            Schema::table('serpro_usage_monthly_aggregates', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_usage_monthly_aggregates', 'cycle_code')) {
                    $table->string('cycle_code', 40)->nullable()->after('period_month');
                }
                if (! Schema::hasColumn('serpro_usage_monthly_aggregates', 'period_kind')) {
                    $table->string('period_kind', 20)->default('CALENDAR_MONTH')->after('cycle_code');
                }
            });
        }

        if (Schema::hasTable('serpro_usage_reconciliations')) {
            Schema::table('serpro_usage_reconciliations', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_usage_reconciliations', 'cycle_code')) {
                    $table->string('cycle_code', 40)->nullable()->after('period_month');
                }
                if (! Schema::hasColumn('serpro_usage_reconciliations', 'period_kind')) {
                    $table->string('period_kind', 20)->default('CALENDAR_MONTH')->after('cycle_code');
                }
            });
        }

        // Reservations: office_id cascade destruiria trilha — restrito em pgsql; sqlite mantém.
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('serpro_api_usage_reservations')) {
            $this->restrictOfficeFk(
                'serpro_api_usage_reservations',
                'serpro_api_usage_reservations_office_id_foreign',
                'office_id',
            );
            $this->restrictOfficeFk(
                'serpro_usage_monthly_aggregates',
                'serpro_usage_monthly_aggregates_office_id_foreign',
                'office_id',
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_billing_invoice_lines')) {
            Schema::dropIfExists('serpro_billing_invoice_lines');
        }
        if (Schema::hasTable('serpro_circuit_breaker_states')) {
            Schema::dropIfExists('serpro_circuit_breaker_states');
        }

        if (Schema::hasTable('serpro_price_versions')) {
            Schema::table('serpro_price_versions', function (Blueprint $table): void {
                foreach (['billing_cycle_kind', 'authorizes_production', 'eligibility', 'source_revision', 'source_hash', 'source_url'] as $col) {
                    if (Schema::hasColumn('serpro_price_versions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('serpro_usage_monthly_aggregates')) {
            Schema::table('serpro_usage_monthly_aggregates', function (Blueprint $table): void {
                foreach (['period_kind', 'cycle_code'] as $col) {
                    if (Schema::hasColumn('serpro_usage_monthly_aggregates', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('serpro_usage_reconciliations')) {
            Schema::table('serpro_usage_reconciliations', function (Blueprint $table): void {
                foreach (['period_kind', 'cycle_code'] as $col) {
                    if (Schema::hasColumn('serpro_usage_reconciliations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }

    private function restrictOfficeFk(string $table, string $constraint, string $column): void
    {
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM pg_constraint WHERE conname = ?',
            [$constraint],
        );
        if ($exists === null) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES offices(id) ON DELETE RESTRICT"
        );
    }
};
