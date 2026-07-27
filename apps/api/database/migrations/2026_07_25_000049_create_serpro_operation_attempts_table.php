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
        Schema::create('serpro_operation_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('environment', 20);
            $table->string('operation_key', 120);
            $table->string('entity_key', 160);
            $table->string('idempotency_key', 190)->unique();
            $table->string('request_tag', 32)->index();
            $table->string('correlation_id', 64)->nullable();
            $table->string('attempt_state', 30);
            $table->bigInteger('reservation_id')->nullable();
            $table->bigInteger('client_id')->nullable();
            $table->boolean('success')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->boolean('simulated')->default(false);
            $table->integer('latency_ms')->nullable();
            $table->string('source_provenance', 40)->nullable();
            $table->string('business_status', 80)->nullable();
            $table->string('functional_route', 40)->nullable();
            $table->jsonb('mensagens')->nullable();
            $table->jsonb('dados')->nullable();
            $table->jsonb('body')->nullable();
            $table->jsonb('headers')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'environment', 'operation_key', 'entity_key'], 'serpro_operation_attempts_tenant_id_environment_op_ee04e145bf');
            $table->index(['tenant_id', 'attempt_state']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_operation_attempts');
    }
};
