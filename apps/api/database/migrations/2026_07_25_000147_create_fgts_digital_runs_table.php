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
        Schema::create('fgts_digital_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('requested_by')->nullable();
            $table->bigInteger('session_id')->nullable();
            $table->bigInteger('fiscal_mutation_operation_id')->nullable();
            $table->bigInteger('tax_guide_id')->nullable();
            $table->bigInteger('tax_guide_version_id')->nullable();
            $table->string('operation', 32);
            $table->string('guide_type', 24)->nullable();
            $table->string('status', 40)->default('PENDING');
            $table->string('code', 80)->nullable();
            $table->string('idempotency_key', 160);
            $table->string('request_digest', 64);
            $table->string('request_vault_object_id', 26)->nullable();
            $table->string('preview_token_hash', 64)->nullable();
            $table->string('confirmation_phrase', 160)->nullable();
            $table->timestampTz('preview_expires_at')->nullable();
            $table->jsonb('request_sanitized')->nullable();
            $table->jsonb('result_sanitized')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'created_at']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_mutation_operation_id'])->references(['id'])->on('fiscal_mutation_operations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requested_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['session_id'])->references(['id'])->on('fgts_digital_sessions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tax_guide_id'])->references(['id'])->on('tax_guides')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tax_guide_version_id'])->references(['id'])->on('tax_guide_versions')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fgts_digital_runs');
    }
};
