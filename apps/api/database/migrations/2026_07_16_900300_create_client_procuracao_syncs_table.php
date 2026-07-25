<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado sincronizado de procuração oficial por cliente (evidência e-CAC).
 * Sem override manual — somente sync oficial (job/plataforma).
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_procuracao_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            /** Referência segura no vault/store — nunca payload bruto em API. */
            $table->string('evidence_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            /** Metadados normalizados de poderes (sem segredo). */
            $table->json('powers_summary')->nullable();
            $table->string('last_check_result', 80)->nullable();
            $table->string('last_sync_error_code', 80)->nullable();
            $table->string('source', 40)->default('official_sync');
            $table->timestamps();

            $table->unique(['office_id', 'client_id'], 'cps_office_client_uq');
            $table->index(['office_id', 'status'], 'cps_office_status_idx');
            $table->index(['office_id', 'last_verified_at'], 'cps_office_verified_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_procuracao_syncs');
    }
};
