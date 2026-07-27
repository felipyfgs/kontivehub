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
        Schema::create('mei_automation_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('fiscal_monitoring_run_id')->nullable();
            $table->bigInteger('fiscal_mutation_operation_id')->nullable();
            $table->uuid('external_job_id')->nullable()->unique();
            $table->string('operation_key', 80);
            $table->string('provider', 24);
            $table->string('status', 32);
            $table->string('idempotency_key', 160);
            $table->string('request_fingerprint', 64);
            $table->smallInteger('attempt_number')->default(1);
            $table->string('source_provenance', 32)->nullable();
            $table->string('verification_kind', 32)->nullable();
            $table->string('portal_version', 40)->nullable();
            $table->string('parser_version', 40)->nullable();
            $table->string('captcha_driver', 32)->nullable();
            $table->bigInteger('captcha_cost_micros')->default(0);
            $table->string('fallback_reason', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 240)->nullable();
            $table->jsonb('safe_metadata')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('sync_lost_at')->nullable();
            $table->jsonb('vault_artifacts')->nullable();
            $table->text('result_payload_encrypted')->nullable();

            $table->index(['tenant_id', 'client_id', 'operation_key']);
            $table->unique(['tenant_id', 'idempotency_key', 'attempt_number'], 'mei_automation_attempts_tenant_id_idempotency_key__e104555607');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_monitoring_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_mutation_operation_id'])->references(['id'])->on('fiscal_mutation_operations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mei_automation_attempts');
    }
};
