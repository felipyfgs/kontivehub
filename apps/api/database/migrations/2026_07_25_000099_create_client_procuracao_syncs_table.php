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
        Schema::create('client_procuracao_syncs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('environment', 32);
            $table->string('status', 32);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->jsonb('power_codes')->nullable();
            $table->string('last_check_result', 80)->nullable();
            $table->string('last_sync_error_code', 80)->nullable();
            $table->string('source', 40)->default('official_sync');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'environment'], 'client_procuracao_syncs_tenant_client_environment_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'last_verified_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_procuracao_syncs');
    }
};
