<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operações fiscais mutantes e reconciliação (tasks 13.1–13.7).
 *
 * - fiscal_mutation_operations: identidade lógica + máquina de estados + snapshot sanitizado
 * - fiscal_mutation_operation_events: trilha de transição (sem payload fiscal/segredo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_mutation_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('idempotency_key', 160);
            $table->string('logical_key', 200);
            $table->string('correlation_id', 64);
            $table->string('preflight_token', 64)->nullable();

            $table->string('environment', 20)->default('TRIAL');
            $table->string('solution_code', 80);
            $table->string('service_code', 120);
            $table->string('operation_code', 120);
            $table->string('module_key', 40)->nullable();
            $table->string('competence_period_key', 20)->nullable();

            $table->string('status', 32)->default('PENDING');
            $table->string('effect_summary', 500)->nullable();
            $table->string('confirmation_phrase', 120)->nullable();
            $table->boolean('confirmation_required')->default(true);
            $table->boolean('confirmed_by_user')->default(false);
            $table->timestampTz('confirmed_at')->nullable();

            // Snapshot pré-operação / request sanitizado (sem payload fiscal bruto)
            $table->json('request_sanitized')->nullable();
            $table->json('pre_operation_snapshot')->nullable();
            $table->json('eligibility_snapshot')->nullable();
            $table->json('cost_estimate')->nullable();
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();

            $table->string('result_code', 80)->nullable();
            $table->string('result_message', 500)->nullable();
            $table->json('result_sanitized')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->string('external_correlation', 120)->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('reconcile_count')->default(0);
            $table->timestampTz('preflight_at')->nullable();
            $table->timestampTz('preflight_expires_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('terminal_at')->nullable();
            $table->timestampTz('last_reconcile_at')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('simulated')->default(false);

            $table->string('denial_code', 60)->nullable();
            $table->text('denial_message')->nullable();

            $table->timestamps();

            $table->unique(['office_id', 'idempotency_key'], 'fmo_office_idempotency_uq');
            $table->index(['office_id', 'status'], 'fmo_office_status_idx');
            $table->index(['office_id', 'client_id', 'logical_key'], 'fmo_office_client_logical_idx');
            $table->index(['office_id', 'correlation_id'], 'fmo_office_correlation_idx');
            $table->index(['office_id', 'solution_code', 'service_code', 'operation_code'], 'fmo_office_op_idx');
            $table->index(['preflight_token'], 'fmo_preflight_token_idx');
        });

        Schema::create('fiscal_mutation_operation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_mutation_operation_id')
                ->constrained('fiscal_mutation_operations')
                ->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('event', 80);
            $table->string('result', 40)->default('SUCCESS');
            $table->string('correlation_id', 64)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable(); // sanitizado
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['office_id', 'fiscal_mutation_operation_id', 'created_at'],
                'fmoe_office_op_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_mutation_operation_events');
        Schema::dropIfExists('fiscal_mutation_operations');
    }
};
