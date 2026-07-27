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
        Schema::create('serpro_api_usage_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('reservation_id')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->bigInteger('client_id')->nullable();
            $table->string('contributor_ref', 40)->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('consumption_class', 30);
            $table->integer('quantity')->default(1);
            $table->string('result', 30);
            $table->string('correlation_id', 64)->nullable()->index();
            $table->bigInteger('price_version_id')->nullable()->index();
            $table->bigInteger('estimated_cost_micros')->nullable();
            $table->boolean('is_billable_attempt')->default(true);
            $table->integer('latency_ms')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->string('operation_key', 120)->nullable()->index();
            $table->string('request_tag', 32)->nullable()->index();
            $table->string('functional_route', 20)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->string('environment', 20)->nullable();
            $table->bigInteger('serpro_contract_id')->nullable();
            $table->string('attempt_state', 30)->nullable();
            $table->string('catalog_revision', 80)->nullable();
            $table->string('price_revision', 80)->nullable();
            $table->string('remote_state', 40)->nullable();
            $table->string('segregation_class', 40)->default('SHADOW');

            $table->index(['tenant_id', 'consumption_class', 'occurred_at'], 'serpro_api_usage_entries_tenant_id_consumption_cla_d88c430e09');
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'service_code', 'occurred_at'], 'serpro_api_usage_entries_tenant_id_service_code_oc_5bb043d818');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['price_version_id'])->references(['id'])->on('serpro_price_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reservation_id'])->references(['id'])->on('serpro_api_usage_reservations')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_api_usage_entries');
    }
};
