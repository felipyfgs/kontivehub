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
        Schema::create('serpro_api_usage_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('idempotency_key', 120)->unique();
            $table->bigInteger('client_id')->nullable();
            $table->string('contributor_ref', 40)->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('consumption_class', 30);
            $table->integer('quantity')->default(1);
            $table->boolean('is_essential')->default(false);
            $table->string('status', 32);
            $table->string('correlation_id', 64)->nullable()->index();
            $table->bigInteger('price_version_id')->nullable();
            $table->bigInteger('estimated_cost_micros')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->boolean('would_block')->default(false);
            $table->string('block_reason', 80)->nullable();
            $table->string('result', 30)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->boolean('possibly_billable')->nullable();
            $table->timestampTz('reserved_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
            $table->string('operation_key', 120)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->string('request_tag', 32)->nullable();
            $table->string('functional_route', 20)->nullable();
            $table->string('environment', 20)->nullable();
            $table->bigInteger('serpro_contract_id')->nullable();
            $table->string('attempt_state', 30)->nullable();
            $table->string('catalog_revision', 80)->nullable();
            $table->string('price_revision', 80)->nullable();
            $table->string('remote_state', 40)->nullable();
            $table->string('durable_result_ref', 64)->nullable();
            $table->string('segregation_class', 40)->default('SHADOW');

            $table->index(['tenant_id', 'reserved_at']);
            $table->index(['tenant_id', 'status', 'reserved_at'], 'serpro_api_usage_reservations_tenant_id_status_res_382161a300');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['price_version_id'])->references(['id'])->on('serpro_price_versions')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_api_usage_reservations');
    }
};
