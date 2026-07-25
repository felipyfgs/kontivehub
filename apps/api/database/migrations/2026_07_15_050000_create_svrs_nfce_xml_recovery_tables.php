<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensões aditivas para recuperação SVRS de nfeProc NFC-e 65 por chave.
 * Não reutiliza last_nsu; não sobrescreve documentos imutáveis.
 *
 * @see openspec/changes/add-svrs-nfce-outbound-xml-retrieval design D5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
            $table->string('origin', 40)->default('MA_OFFICIAL_PACKAGE')->after('mode');
            $table->string('access_key', 50)->nullable()->after('origin');
            $table->foreignId('outbound_number_state_id')->nullable()->after('access_key')
                ->constrained('outbound_number_states')->nullOnDelete();
            $table->string('recovery_status', 40)->nullable()->after('status');
            $table->string('failure_reason', 60)->nullable()->after('last_error');
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('failure_reason');
            $table->timestampTz('next_attempt_at')->nullable()->after('attempt_count');
            $table->string('correlation_id', 64)->nullable()->after('next_attempt_at');
            $table->string('sha256', 64)->nullable()->after('correlation_id');
            $table->foreignId('dfe_document_id')->nullable()->after('sha256')
                ->constrained('dfe_documents')->nullOnDelete();

            $table->index(['office_id', 'origin', 'recovery_status'], 'ma_retrieval_office_origin_status_idx');
            $table->index(['office_id', 'access_key'], 'ma_retrieval_office_access_key_idx');
            $table->index(['office_id', 'next_attempt_at'], 'ma_retrieval_office_next_attempt_idx');
        });

        // Uma recuperação lógica ativa por office+perfil+chave+origem (partial unique via índice composto;
        // estados terminais permitem novo ciclo se necessário — enforced na aplicação + unique parcial em PG).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(<<<'SQL'
                CREATE UNIQUE INDEX ma_retrieval_active_svrs_unique
                ON ma_outbound_retrieval_requests (office_id, outbound_capture_profile_id, access_key, origin)
                WHERE origin = 'SVRS_PORTAL_BY_KEY'
                  AND access_key IS NOT NULL
                  AND recovery_status IS NOT NULL
                  AND recovery_status NOT IN (
                    'CAPTURED', 'NOT_AVAILABLE_VISIBLE', 'BLOCKED', 'RESOLVED_BY_OTHER_SOURCE'
                  )
            SQL);
        } else {
            // SQLite/tests: índice composto sem partial (unicidade lógica reforçada na aplicação)
            Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
                $table->index(
                    ['office_id', 'outbound_capture_profile_id', 'access_key', 'origin'],
                    'ma_retrieval_office_profile_key_origin_idx'
                );
            });
        }

        Schema::create('outbound_xml_recovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ma_outbound_retrieval_request_id')
                ->constrained('ma_outbound_retrieval_requests')->cascadeOnDelete();
            $table->foreignId('outbound_capture_profile_id')
                ->constrained('outbound_capture_profiles')->cascadeOnDelete();
            $table->foreignId('outbound_number_state_id')->nullable()
                ->constrained('outbound_number_states')->nullOnDelete();
            $table->string('access_key', 50);
            $table->string('correlation_id', 64);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('result', 40); // CAPTURED | RETRY_SCHEDULED | BLOCKED | ...
            $table->string('failure_reason', 60)->nullable();
            $table->string('transport_outcome', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('parser_version', 20)->nullable();
            $table->unsignedInteger('get_latency_ms')->nullable();
            $table->unsignedInteger('post_latency_ms')->nullable();
            $table->unsignedInteger('total_latency_ms')->nullable();
            $table->string('sanitized_detail', 500)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['ma_outbound_retrieval_request_id', 'attempt_number'],
                'outbound_xml_recovery_attempt_num_unique'
            );
            $table->index(['office_id', 'result', 'created_at']);
            $table->index(['office_id', 'correlation_id']);
            $table->index(['office_id', 'access_key']);
            // Sem HTML/XML bruto; sem NSU inventado.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_xml_recovery_attempts');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS ma_retrieval_active_svrs_unique');
        } else {
            Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
                $table->dropIndex('ma_retrieval_office_profile_key_origin_idx');
            });
        }

        Schema::table('ma_outbound_retrieval_requests', function (Blueprint $table) {
            $table->dropIndex('ma_retrieval_office_origin_status_idx');
            $table->dropIndex('ma_retrieval_office_access_key_idx');
            $table->dropIndex('ma_retrieval_office_next_attempt_idx');

            $table->dropConstrainedForeignId('dfe_document_id');
            $table->dropConstrainedForeignId('outbound_number_state_id');
            $table->dropColumn([
                'origin',
                'access_key',
                'recovery_status',
                'failure_reason',
                'attempt_count',
                'next_attempt_at',
                'correlation_id',
                'sha256',
            ]);
        });
    }
};
