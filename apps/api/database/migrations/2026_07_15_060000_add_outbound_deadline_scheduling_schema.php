<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de prazo/agenda e snapshots de capacidade para captura gradual de saídas.
 * Rollback não destrutivo: só remove colunas/tabelas novas; não apaga XMLs/aquisições.
 *
 * @see openspec/changes/schedule-gradual-outbound-xml-capture-by-deadline
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('ma_outbound_retrieval_requests', 'due_at')) {
                $table->timestampTz('due_at')->nullable()->after('competence');
                $table->timestampTz('target_at')->nullable()->after('due_at');
                $table->string('deadline_source', 30)->nullable()->after('target_at'); // AUTHORIZATION | ACCESS_KEY_YM | MANUAL
                $table->string('urgency_band', 20)->nullable()->after('deadline_source');
                $table->string('deadline_status', 30)->nullable()->after('urgency_band');
                $table->unsignedSmallInteger('svrs_transaction_count')->default(0)->after('attempt_count');
                $table->timestampTz('planned_at')->nullable()->after('next_attempt_at');
                $table->timestampTz('dispatched_at')->nullable()->after('planned_at');
                $table->timestampTz('accommodation_until')->nullable()->after('dispatched_at');
                $table->timestampTz('captured_at')->nullable()->after('ingested_at');
                $table->boolean('captured_before_due')->nullable()->after('captured_at');
                $table->string('capture_source', 40)->nullable()->after('captured_before_due');
                $table->string('root_cnpj', 8)->nullable()->after('access_key');
                $table->boolean('capacity_at_risk')->default(false)->after('deadline_status');
                $table->string('slot_key', 80)->nullable()->after('correlation_id'); // office|key|attempt

                $table->index(['office_id', 'due_at', 'urgency_band'], 'ma_retrieval_office_due_band_idx');
                $table->index(['office_id', 'competence', 'urgency_band'], 'ma_retrieval_office_comp_band_idx');
                $table->index(['office_id', 'next_attempt_at', 'urgency_band'], 'ma_retrieval_office_next_band_idx');
                $table->index(['office_id', 'root_cnpj', 'model'], 'ma_retrieval_office_root_model_idx');
                $table->index(['office_id', 'slot_key'], 'ma_retrieval_office_slot_key_idx');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS ma_retrieval_slot_attempt_unique
                ON ma_outbound_retrieval_requests (office_id, access_key, origin, svrs_transaction_count)
                WHERE origin = 'SVRS_PORTAL_BY_KEY'
                  AND access_key IS NOT NULL
                  AND recovery_status IS NOT NULL
                  AND recovery_status NOT IN (
                    'CAPTURED', 'NOT_AVAILABLE_VISIBLE', 'BLOCKED', 'RESOLVED_BY_OTHER_SOURCE'
                  )
            SQL);
        }

        Schema::create('outbound_capacity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete(); // null = coorte global
            $table->string('competence', 7); // YYYY-MM
            $table->string('scope', 40)->default('COHORT'); // COHORT | OFFICE | ROOT
            $table->string('root_cnpj', 8)->nullable();
            $table->string('model', 5)->nullable();
            $table->unsignedInteger('demand_exchanges')->default(0);
            $table->unsignedInteger('safe_capacity_exchanges')->default(0);
            $table->unsignedInteger('nominal_capacity_exchanges')->default(0);
            $table->integer('slack_exchanges')->default(0);
            $table->decimal('slack_ratio', 8, 4)->nullable();
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_planned')->default(0);
            $table->unsignedInteger('items_attention')->default(0);
            $table->unsignedInteger('items_contingency')->default(0);
            $table->unsignedInteger('items_overdue')->default(0);
            $table->unsignedInteger('items_captured')->default(0);
            $table->unsignedInteger('items_capacity_at_risk')->default(0);
            $table->timestampTz('estimated_completion_at')->nullable();
            $table->timestampTz('target_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->boolean('at_risk')->default(false);
            $table->json('metrics')->nullable(); // sem chave completa / sem segredos
            $table->timestampTz('calculated_at');
            $table->timestamps();

            $table->index(['office_id', 'competence', 'calculated_at'], 'outbound_capacity_office_comp_calc_idx');
            $table->index(['competence', 'at_risk'], 'outbound_capacity_comp_risk_idx');
        });

        Schema::create('outbound_monthly_readiness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('competence', 7); // YYYY-MM
            $table->string('status', 32)->default('NOT_READY'); // COMPLETE_KNOWN | PARTIAL_CONFIRMED | NOT_READY
            $table->unsignedInteger('known_total')->default(0);
            $table->unsignedInteger('captured_total')->default(0);
            $table->unsignedInteger('pending_total')->default(0);
            $table->foreignId('export_id')->nullable()->constrained('exports')->nullOnDelete();
            $table->string('manifest_vault_object_id', 26)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('confirmation_notes')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'competence'], 'outbound_monthly_readiness_office_comp_unique');
        });

        // Preferência de timezone SLA no office (opcional)
        if (Schema::hasTable('offices') && ! Schema::hasColumn('offices', 'deadline_timezone')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->string('deadline_timezone', 64)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('offices', 'deadline_timezone')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->dropColumn('deadline_timezone');
            });
        }

        Schema::dropIfExists('outbound_monthly_readiness');
        Schema::dropIfExists('outbound_capacity_snapshots');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS ma_retrieval_slot_attempt_unique');
        }

        if (Schema::hasColumn('ma_outbound_retrieval_requests', 'due_at')) {
            Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
                $table->dropIndex('ma_retrieval_office_due_band_idx');
                $table->dropIndex('ma_retrieval_office_comp_band_idx');
                $table->dropIndex('ma_retrieval_office_next_band_idx');
                $table->dropIndex('ma_retrieval_office_root_model_idx');
                $table->dropIndex('ma_retrieval_office_slot_key_idx');
                $table->dropColumn([
                    'due_at', 'target_at', 'deadline_source', 'urgency_band', 'deadline_status',
                    'svrs_transaction_count', 'planned_at', 'dispatched_at', 'accommodation_until',
                    'captured_at', 'captured_before_due', 'capture_source', 'root_cnpj',
                    'capacity_at_risk', 'slot_key',
                ]);
            });
        }
    }
};
