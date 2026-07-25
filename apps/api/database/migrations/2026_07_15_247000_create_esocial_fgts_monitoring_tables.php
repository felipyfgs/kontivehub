<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoramento parcial FGTS via eSocial (tasks 12.1–12.7).
 *
 * - esocial_event_evidences: S-5003, S-5013, S-1299 por competência/estabelecimento
 * - fgts_competence_statuses: estados independentes (fechamento/totalização/guia/pagamento)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esocial_event_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('fiscal_evidence_artifact_id')->nullable()
                ->constrained('fiscal_evidence_artifacts')->nullOnDelete();
            $table->string('competence_period_key', 7); // YYYY-MM
            $table->string('event_code', 20); // S-5003 | S-5013 | S-1299
            $table->string('event_version', 40)->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->string('establishment_cnpj', 14)->nullable();
            $table->string('content_sha256', 64);
            $table->string('vault_object_id', 26)->nullable();
            $table->string('content_type', 80)->default('application/json');
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('source', 80)->default('esocial');
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('observed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'competence_period_key', 'event_code', 'content_sha256'],
                'eee_office_client_comp_event_sha_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'competence_period_key', 'event_code'],
                'eee_office_client_comp_event_idx'
            );
            $table->index(['office_id', 'establishment_id', 'competence_period_key'], 'eee_office_est_comp_idx');
            $table->index(['office_id', 'run_id'], 'eee_office_run_idx');
        });

        Schema::create('fgts_competence_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()
                ->constrained('fiscal_snapshots')->nullOnDelete();
            $table->string('competence_period_key', 7); // YYYY-MM
            /** Fechamento eSocial (S-1299): CONFIRMED | ABSENT | UNKNOWN */
            $table->string('closure_status', 20)->default('UNKNOWN');
            /** Totalização (S-5003/S-5013): PRESENT | ABSENT | UNKNOWN */
            $table->string('totalization_status', 20)->default('UNKNOWN');
            /** Guia FGTS Digital: sempre UNSUPPORTED no MVP (sem API pública). */
            $table->string('guide_status', 20)->default('UNSUPPORTED');
            /** Pagamento FGTS Digital: sempre UNSUPPORTED no MVP. */
            $table->string('payment_status', 20)->default('UNSUPPORTED');
            $table->string('coverage', 30)->default('PARTIAL');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->timestampTz('closure_observed_at')->nullable();
            $table->timestampTz('totalizer_observed_at')->nullable();
            $table->timestampTz('totalizer_due_by')->nullable(); // fim da janela pós-fechamento
            $table->timestampTz('last_synced_at')->nullable();
            $table->json('limitations')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'establishment_id', 'competence_period_key'],
                'fcs_office_client_est_comp_uq'
            );
            $table->index(['office_id', 'client_id', 'situation'], 'fcs_office_client_sit_idx');
            $table->index(['office_id', 'competence_period_key'], 'fcs_office_comp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fgts_competence_statuses');
        Schema::dropIfExists('esocial_event_evidences');
    }
};
