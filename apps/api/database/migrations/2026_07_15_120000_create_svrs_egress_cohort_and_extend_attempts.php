<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coorte/breaker durável + extensões de tentativas para governança SVRS compartilhada.
 * Rollback não destrutivo: drop de colunas/tabelas novas; não apaga dfe/aquisições.
 *
 * @see openspec/changes/add-resilient-svrs-nfe55-outbound-xml-retrieval
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('svrs_egress_cohort_states', function (Blueprint $table) {
            $table->id();
            $table->string('cohort_id', 64)->unique();
            $table->string('state', 20)->default('closed'); // closed | open | half_open
            $table->string('cause', 60)->nullable();
            $table->unsignedTinyInteger('tier')->default(0);
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('next_probe_at')->nullable();
            $table->string('canary_access_key_hash', 64)->nullable();
            $table->string('canary_key_mask', 20)->nullable();
            $table->string('template_fingerprint', 64)->nullable();
            $table->string('active_deployment_id', 64)->nullable();
            $table->timestampTz('last_exchange_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['state', 'next_probe_at']);
        });

        Schema::table('outbound_xml_recovery_attempts', function (Blueprint $table) {
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'model')) {
                $table->string('model', 4)->nullable()->after('access_key');
            }
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'origin')) {
                $table->string('origin', 40)->nullable()->after('model');
            }
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'cohort_id')) {
                $table->string('cohort_id', 64)->nullable()->after('origin');
            }
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'exchanges_reserved')) {
                $table->unsignedTinyInteger('exchanges_reserved')->nullable()->after('cohort_id');
            }
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'exchanges_consumed')) {
                $table->unsignedTinyInteger('exchanges_consumed')->nullable()->after('exchanges_reserved');
            }
            if (! Schema::hasColumn('outbound_xml_recovery_attempts', 'reservation_id')) {
                $table->string('reservation_id', 64)->nullable()->after('exchanges_consumed');
            }
        });

        Schema::table('outbound_xml_recovery_attempts', function (Blueprint $table) {
            $table->index(['office_id', 'origin', 'result'], 'outbound_xml_attempt_office_origin_result_idx');
            $table->index(['cohort_id', 'created_at'], 'outbound_xml_attempt_cohort_created_idx');
        });

        Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('ma_outbound_retrieval_requests', 'source_selected')) {
                $table->string('source_selected', 40)->nullable()->after('origin');
            }
            if (! Schema::hasColumn('ma_outbound_retrieval_requests', 'exchanges_reserved')) {
                $table->unsignedTinyInteger('exchanges_reserved')->nullable()->after('attempt_count');
            }
            if (! Schema::hasColumn('ma_outbound_retrieval_requests', 'exchanges_consumed')) {
                $table->unsignedTinyInteger('exchanges_consumed')->nullable()->after('exchanges_reserved');
            }
        });
    }

    public function down(): void
    {
        // Não remove documentos, aquisições nem XML — só metadados de governança.
        if (Schema::hasTable('ma_outbound_retrieval_requests')) {
            Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
                foreach (['source_selected', 'exchanges_reserved', 'exchanges_consumed'] as $col) {
                    if (Schema::hasColumn('ma_outbound_retrieval_requests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('outbound_xml_recovery_attempts')) {
            Schema::table('outbound_xml_recovery_attempts', function (Blueprint $table) {
                try {
                    $table->dropIndex('outbound_xml_attempt_office_origin_result_idx');
                } catch (Throwable) {
                }
                try {
                    $table->dropIndex('outbound_xml_attempt_cohort_created_idx');
                } catch (Throwable) {
                }
                foreach (['model', 'origin', 'cohort_id', 'exchanges_reserved', 'exchanges_consumed', 'reservation_id'] as $col) {
                    if (Schema::hasColumn('outbound_xml_recovery_attempts', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('svrs_egress_cohort_states');
    }
};
