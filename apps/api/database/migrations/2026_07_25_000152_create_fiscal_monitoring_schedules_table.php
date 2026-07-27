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
        Schema::create('fiscal_monitoring_schedules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('fiscal_category_id')->nullable();
            $table->bigInteger('category_link_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80)->default('MONITOR');
            $table->boolean('is_enabled')->default(true);
            $table->integer('interval_minutes')->default(60);
            $table->smallInteger('preferred_minute')->default(0);
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_result', 30)->nullable();
            $table->string('last_skip_reason', 80)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['is_enabled', 'next_run_at']);
            $table->unique(['tenant_id', 'client_id', 'system_code', 'service_code', 'operation_code'], 'fiscal_monitoring_schedules_tenant_id_client_id_sy_9fd21c92e3');
            $table->index(['tenant_id', 'is_enabled', 'next_run_at'], 'fiscal_monitoring_schedules_tenant_id_is_enabled_n_86fae09e9a');
            $table->foreign(['category_link_id'])->references(['id'])->on('tenant_fiscal_category_links')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_category_id'])->references(['id'])->on('fiscal_categories')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_monitoring_schedules');
    }
};
