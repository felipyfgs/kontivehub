<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tentativas duráveis do executor central Integra Contador.
 * State machine: reserved → dispatched → acknowledged|uncertain → reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serpro_operation_attempts')) {
            return;
        }

        Schema::create('serpro_operation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('operation_key', 120);
            $table->string('entity_key', 160);
            $table->string('idempotency_key', 190)->unique();
            $table->string('request_tag', 32);
            $table->string('correlation_id', 64)->nullable();
            $table->string('attempt_state', 30);
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->boolean('success')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('simulated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('source_provenance', 40)->nullable();
            $table->string('business_status', 80)->nullable();
            $table->string('functional_route', 40)->nullable();
            $table->json('mensagens')->nullable();
            $table->json('dados')->nullable();
            $table->json('body')->nullable();
            $table->json('headers')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['office_id', 'environment', 'operation_key', 'entity_key'],
                'serpro_op_attempt_scope_idx',
            );
            $table->index(['office_id', 'attempt_state'], 'serpro_op_attempt_state_idx');
            $table->index(['request_tag'], 'serpro_op_attempt_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_operation_attempts');
    }
};
