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
        Schema::create('dctfweb_mutation_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('competence_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('period_key', 20)->nullable();
            $table->string('idempotency_key', 160);
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('blocked_retry_until')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'blocked_retry_until'], 'dctfweb_mutation_attempts_tenant_id_status_blocked_be3ff954cc');
            $table->index(['tenant_id', 'client_id', 'status']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dctfweb_mutation_attempts');
    }
};
