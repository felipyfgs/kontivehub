<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs duráveis para jobs SERPRO/fiscal (cursor, retry, erro sanitizado)
 * e protocolo assíncrono de Eventos de Atualização (/Monitorar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_async_job_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job_type', 80);
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->string('cursor', 255)->nullable();
            $table->unsignedInteger('pages_done')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('flag_checked_at_dispatch')->default(false);
            $table->boolean('flag_checked_at_handle')->default(false);
            $table->json('progress')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['job_type', 'status'], 'serpro_async_job_type_status_idx');
            $table->index(['office_id', 'client_id', 'job_type'], 'serpro_async_job_office_client_type_idx');
            $table->index(['correlation_id']);
            $table->index(['next_retry_at']);
        });

        Schema::create('serpro_eventos_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20);
            $table->string('person_type', 2); // PF | PJ
            $table->string('phase', 40)->default('IDLE');
            $table->string('protocol', 64)->nullable();
            $table->unsignedInteger('tempo_espera_medio_ms')->nullable();
            $table->unsignedInteger('tempo_limite_em_min')->nullable();
            $table->timestampTz('not_before_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('result_consumed')->default(false);
            $table->boolean('one_shot_complete')->default(false);
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable();
            $table->string('operation_key_solicit', 120)->nullable();
            $table->string('operation_key_obter', 120)->nullable();
            $table->string('evento', 80)->nullable();
            $table->unsignedInteger('contributors_in_batch')->default(0);
            $table->string('result_fingerprint', 64)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('simulated')->default(false);
            $table->json('progress')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestampTz('solicited_at')->nullable();
            $table->timestampTz('obtained_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'protocol'], 'serpro_eventos_office_protocol_uq');
            $table->index(['office_id', 'status', 'phase'], 'serpro_eventos_office_status_phase_idx');
            $table->index(['expires_at']);
            $table->index(['not_before_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_eventos_runs');
        Schema::dropIfExists('serpro_async_job_runs');
    }
};
