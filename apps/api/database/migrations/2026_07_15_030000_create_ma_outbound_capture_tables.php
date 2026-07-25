<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema dedicado a captura de saídas MA por nNF.
 * Invariante: não reutiliza last_nsu / channel_sync_cursors para posição nNF.
 *
 * @see openspec/changes/build-ma-outbound-nfe-nfce-capture design D4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_capture_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('uf', 2)->default('MA');
            $table->string('environment', 40); // production | homologation
            $table->string('model', 5); // 55 | 65
            $table->string('mode', 20)->default('ASSISTED'); // ASSISTED | AUTOMATIC
            $table->string('status', 32)->default('DRAFT');
            $table->boolean('consent_recorded')->default(false);
            $table->timestampTz('consent_recorded_at')->nullable();
            $table->string('mandate_reference', 255)->nullable();
            $table->boolean('allowlisted')->default(false);
            $table->timestampTz('allowlisted_at')->nullable();
            $table->boolean('kill_switch')->default(false);
            $table->string('kill_switch_reason', 500)->nullable();
            $table->timestampTz('kill_switch_at')->nullable();
            // Referência opcional de CSC no vault (somente metadados — sem valor)
            $table->string('csc_vault_object_id', 26)->nullable();
            $table->string('csc_id', 20)->nullable(); // ID CSC público (não é o token)
            $table->boolean('csc_configured')->default(false);
            $table->timestampTz('csc_configured_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['establishment_id', 'environment', 'model'],
                'outbound_profiles_estab_env_model_unique'
            );
            $table->index(['office_id', 'status']);
            $table->index(['office_id', 'client_id']);
        });

        Schema::create('outbound_series_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_capture_profile_id')->constrained('outbound_capture_profiles')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 40);
            $table->string('model', 5);
            $table->unsignedInteger('series');
            $table->unsignedBigInteger('seed_nnf');
            $table->unsignedBigInteger('discovery_position'); // próximo nNF a reconciliar
            $table->unsignedBigInteger('highest_confirmed_nnf')->nullable();
            $table->string('status', 32)->default('SEED_READY');
            $table->string('tp_emis', 5)->default('1');
            $table->string('seed_access_key', 50)->nullable();
            $table->string('seed_vault_object_id', 26)->nullable();
            $table->string('seed_sha256', 64)->nullable();
            $table->timestampTz('seed_issued_at')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner', 100)->nullable();
            $table->text('last_error')->nullable();
            $table->string('last_cstat', 10)->nullable();
            $table->boolean('series_closed_for_mutation')->default(false);
            $table->timestampTz('series_closed_at')->nullable();
            $table->string('erp_coordination_ref', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['establishment_id', 'environment', 'model', 'series'],
                'outbound_series_estab_env_model_series_unique'
            );
            $table->index(['office_id', 'status', 'next_run_at']);
            $table->index(['outbound_capture_profile_id']);
            // Invariante de domínio: NÃO há coluna last_nsu nesta tabela.
        });

        Schema::create('outbound_number_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_capture_profile_id')->constrained('outbound_capture_profiles')->cascadeOnDelete();
            $table->foreignId('outbound_series_cursor_id')->constrained('outbound_series_cursors')->cascadeOnDelete();
            $table->unsignedInteger('series');
            $table->unsignedBigInteger('nnf');
            $table->string('status', 40)->default('CONSULT_QUEUED');
            $table->string('candidate_access_key', 50)->nullable();
            $table->string('candidate_cnf', 12)->nullable();
            $table->string('discovered_access_key', 50)->nullable();
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo', 500)->nullable();
            $table->string('protocol', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('key_discovered_at')->nullable();
            $table->timestampTz('xml_captured_at')->nullable();
            $table->foreignId('dfe_document_id')->nullable()->constrained('dfe_documents')->nullOnDelete();
            $table->json('sanitized_response')->nullable();
            $table->text('block_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['outbound_capture_profile_id', 'series', 'nnf'],
                'outbound_number_profile_series_nnf_unique'
            );
            $table->index(['office_id', 'status']);
            $table->index(['outbound_series_cursor_id', 'status']);
            $table->index(['discovered_access_key']);
        });

        Schema::create('ma_outbound_retrieval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_capture_profile_id')->constrained('outbound_capture_profiles')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 40);
            $table->string('model', 5);
            $table->string('direction', 10)->default('OUT');
            $table->string('competence', 7); // YYYY-MM
            $table->string('status', 32)->default('PENDING');
            $table->string('mode', 20)->default('ASSISTED');
            $table->string('external_ref', 120)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('ingested_at')->nullable();
            $table->unsignedInteger('files_expected')->nullable();
            $table->unsignedInteger('files_ingested')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['office_id', 'status']);
            $table->index(['establishment_id', 'competence', 'model']);
            // Sem FK / semântica de cursor NSU.
        });

        Schema::create('outbound_capture_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_capture_profile_id')->constrained('outbound_capture_profiles')->cascadeOnDelete();
            $table->foreignId('outbound_series_cursor_id')->nullable()->constrained('outbound_series_cursors')->nullOnDelete();
            $table->string('run_type', 40)->default('SEQUENCE_QUERY'); // SEQUENCE_QUERY | PACKAGE_INGEST | MUTATING_SAGA
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedBigInteger('nnf_start')->nullable();
            $table->unsignedBigInteger('nnf_end')->nullable();
            $table->unsignedInteger('numbers_consulted')->default(0);
            $table->unsignedInteger('keys_discovered')->default(0);
            $table->unsignedInteger('xml_persisted')->default(0);
            $table->unsignedInteger('gaps_open')->default(0);
            $table->unsignedInteger('attempts_total')->default(0);
            $table->string('result_summary', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('triggered_by', 40)->default('scheduler'); // scheduler | operator | admin
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'status', 'created_at']);
            $table->index(['outbound_series_cursor_id', 'created_at']);
        });

        Schema::create('document_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dfe_document_id')->constrained('dfe_documents')->cascadeOnDelete();
            $table->string('access_key', 50)->nullable();
            $table->string('source', 40); // DocumentAcquisitionSource
            $table->string('channel', 40)->nullable(); // CaptureChannel when applicable
            $table->string('sha256', 64);
            $table->boolean('is_canonical')->default(true);
            $table->boolean('bytes_diverge_from_canonical')->default(false);
            $table->string('quarantine_reason', 255)->nullable();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ma_outbound_retrieval_request_id')->nullable()
                ->constrained('ma_outbound_retrieval_requests')->nullOnDelete();
            $table->foreignId('outbound_number_state_id')->nullable()
                ->constrained('outbound_number_states')->nullOnDelete();
            $table->json('metadata')->nullable(); // sem segredos / sem XML bruto
            $table->timestamps();

            $table->unique(['dfe_document_id', 'source', 'sha256'], 'document_acquisitions_doc_source_sha');
            $table->index(['office_id', 'access_key']);
            $table->index(['office_id', 'source']);
        });

        // Extensão nfe_documents: finalidade técnica e fonte de captura
        Schema::table('nfe_documents', function (Blueprint $table) {
            $table->string('purpose', 20)->default('COMMERCIAL')->after('direction');
            $table->string('acquisition_source', 40)->nullable()->after('purpose');
            $table->index(['office_id', 'purpose']);
            $table->index(['office_id', 'acquisition_source']);
        });
    }

    public function down(): void
    {
        Schema::table('nfe_documents', function (Blueprint $table) {
            $table->dropIndex(['office_id', 'purpose']);
            $table->dropIndex(['office_id', 'acquisition_source']);
            $table->dropColumn(['purpose', 'acquisition_source']);
        });

        Schema::dropIfExists('document_acquisitions');
        Schema::dropIfExists('outbound_capture_runs');
        Schema::dropIfExists('ma_outbound_retrieval_requests');
        Schema::dropIfExists('outbound_number_states');
        Schema::dropIfExists('outbound_series_cursors');
        Schema::dropIfExists('outbound_capture_profiles');
    }
};
