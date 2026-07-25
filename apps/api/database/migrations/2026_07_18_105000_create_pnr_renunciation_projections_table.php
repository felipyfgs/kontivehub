<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeções sanitizadas das consultas de renúncia do PNR Contador.
 *
 * O comprovante nunca é armazenado nesta tabela: apenas a referência opaca
 * para o cofre e metadados que podem ser mostrados ao escritório.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_pnr_renunciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('contributor_cnpj', 14);
            $table->unsignedBigInteger('renunciation_id');
            $table->string('status', 40)->default('UNKNOWN');
            $table->string('history_evidence_version', 64)->nullable();
            $table->string('status_evidence_version', 64)->nullable();
            $table->string('source_provenance', 32)->default('UNVERIFIED');
            $table->json('summary_sanitized')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampTz('refreshed_at')->nullable();
            $table->string('receipt_vault_object_id', 26)->nullable();
            $table->char('receipt_sha256', 64)->nullable();
            $table->string('receipt_mime_type', 100)->nullable();
            $table->unsignedInteger('receipt_byte_size')->nullable();
            $table->timestampTz('receipt_observed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'renunciation_id'],
                'fiscal_pnr_renunciations_office_client_id_uq',
            );
            $table->index(['office_id', 'client_id', 'status'], 'fiscal_pnr_renunciations_client_status_idx');
            $table->index(['office_id', 'contributor_cnpj'], 'fiscal_pnr_renunciations_office_cnpj_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_pnr_renunciations');
    }
};
