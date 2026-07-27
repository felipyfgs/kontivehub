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
        Schema::create('pgdasd_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('projection_id');
            $table->string('kind', 20);
            $table->string('period_key', 20);
            $table->string('logical_key', 64);
            $table->string('raw_operation_type', 80)->nullable();
            $table->string('normalized_operation_type', 40)->nullable();
            $table->string('declaration_number', 80)->nullable();
            $table->string('das_number', 80)->nullable();
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->string('malha', 80)->nullable();
            $table->boolean('payment_located')->nullable();
            $table->timestampTz('payment_observed_at')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->bigInteger('source_run_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('amount_source', 32)->nullable();
            $table->string('amount_parser_version', 64)->nullable();
            $table->timestampTz('amount_resolved_at')->nullable();
            $table->bigInteger('amount_source_artifact_id')->nullable();
            $table->string('pagtoweb_payment_status', 16)->nullable();
            $table->timestampTz('pagtoweb_verified_at')->nullable();
            $table->date('pagtoweb_paid_at')->nullable();
            $table->bigInteger('pagtoweb_amount_cents')->nullable();
            $table->bigInteger('pagtoweb_source_run_id')->nullable();
            $table->bigInteger('pagtoweb_source_item_id')->nullable();

            $table->unique(['tenant_id', 'client_id', 'logical_key']);
            $table->index(['tenant_id', 'client_id', 'pagtoweb_payment_status', 'pagtoweb_verified_at'], 'pgdasd_operations_tenant_id_client_id_pagtoweb_pay_944bc2ea25');
            $table->index(['tenant_id', 'client_id', 'period_key', 'kind']);
            $table->index(['tenant_id', 'projection_id', 'transmitted_at']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['pagtoweb_source_item_id'])->references(['id'])->on('pagtoweb_payment_list_items')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['pagtoweb_source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgdasd_operations');
    }
};
