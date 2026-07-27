<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('serpro_eventos_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id')->nullable();
            $table->string('environment', 20);
            $table->string('person_type', 2);
            $table->string('phase', 40)->default('IDLE');
            $table->string('protocol', 64)->nullable();
            $table->integer('tempo_espera_medio_ms')->nullable();
            $table->integer('tempo_limite_em_min')->nullable();
            $table->timestampTz('not_before_at')->nullable()->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->boolean('result_consumed')->default(false);
            $table->boolean('one_shot_complete')->default(false);
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable();
            $table->string('operation_key_solicit', 120)->nullable();
            $table->string('operation_key_obter', 120)->nullable();
            $table->string('evento', 80)->nullable();
            $table->integer('contributors_in_batch')->default(0);
            $table->string('result_fingerprint', 64)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('simulated')->default(false);
            $table->jsonb('progress')->nullable();
            $table->jsonb('result_summary')->nullable();
            $table->timestampTz('solicited_at')->nullable();
            $table->timestampTz('obtained_at')->nullable();
            $table->timestampsTz();
            $table->string('result_vault_object_id', 26)->nullable();
            $table->string('result_payload_sha256', 64)->nullable();
            $table->timestampTz('remote_result_received_at')->nullable();
            $table->string('local_processing_status', 32)->default('NOT_RECEIVED');
            $table->timestampTz('local_processed_at')->nullable();

            $table->unique(['tenant_id', 'protocol']);
            $table->index(['tenant_id', 'status', 'phase']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_eventos_runs');
    }
};
