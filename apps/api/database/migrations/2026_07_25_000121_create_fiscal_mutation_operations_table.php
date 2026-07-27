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
        Schema::create('fiscal_mutation_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('requested_by')->nullable();
            $table->string('idempotency_key', 160);
            $table->string('logical_key', 200);
            $table->string('correlation_id', 64);
            $table->string('preflight_token', 64)->nullable()->index();
            $table->string('environment', 20)->default('TRIAL');
            $table->string('solution_code', 80);
            $table->string('service_code', 120);
            $table->string('operation_code', 120);
            $table->string('operation_key', 160);
            $table->string('module_key', 40)->nullable();
            $table->string('competence_period_key', 20)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('effect_summary', 500)->nullable();
            $table->string('confirmation_phrase', 120)->nullable();
            $table->boolean('confirmation_required')->default(true);
            $table->boolean('confirmed_by_user')->default(false);
            $table->timestampTz('confirmed_at')->nullable();
            $table->jsonb('request_sanitized')->nullable();
            $table->jsonb('pre_operation_snapshot')->nullable();
            $table->jsonb('eligibility_snapshot')->nullable();
            $table->jsonb('cost_estimate')->nullable();
            $table->bigInteger('estimated_cost_micros')->nullable();
            $table->string('result_code', 80)->nullable();
            $table->string('result_message', 500)->nullable();
            $table->jsonb('result_sanitized')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->string('external_correlation', 120)->nullable();
            $table->integer('attempt_count')->default(0);
            $table->integer('reconcile_count')->default(0);
            $table->timestampTz('preflight_at')->nullable();
            $table->timestampTz('preflight_expires_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->timestampTz('last_reconcile_at')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->boolean('simulated')->default(false);
            $table->string('denial_code', 60)->nullable();
            $table->text('denial_message')->nullable();
            $table->timestampsTz();
            $table->text('request_payload_encrypted')->nullable();
            $table->char('request_payload_digest', 64)->nullable();

            $table->index(['tenant_id', 'client_id', 'logical_key'], 'fiscal_mutation_operations_tenant_id_client_id_log_67eb7c5322');
            $table->index(['tenant_id', 'correlation_id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'solution_code', 'service_code', 'operation_code'], 'fiscal_mutation_operations_tenant_id_solution_code_b234ab79d6');
            $table->index(['tenant_id', 'operation_key']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['requested_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_mutation_operations');
    }
};
