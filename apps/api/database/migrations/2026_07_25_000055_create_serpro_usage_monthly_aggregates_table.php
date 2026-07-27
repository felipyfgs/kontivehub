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
        Schema::create('serpro_usage_monthly_aggregates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scope', 20);
            $table->bigInteger('tenant_id')->nullable();
            $table->smallInteger('period_year');
            $table->smallInteger('period_month');
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('consumption_class', 30)->nullable();
            $table->string('aggregate_key', 191)->unique();
            $table->bigInteger('entry_count')->default(0);
            $table->bigInteger('total_quantity')->default(0);
            $table->bigInteger('total_estimated_cost_micros')->default(0);
            $table->bigInteger('unknown_class_count')->default(0);
            $table->bigInteger('billable_attempt_count')->default(0);
            $table->timestampTz('recomputed_at');
            $table->timestampsTz();
            $table->string('cycle_code', 40)->nullable();
            $table->string('period_kind', 20)->default('CALENDAR_MONTH');

            $table->index(['tenant_id', 'period_year', 'period_month'], 'serpro_usage_monthly_aggregates_tenant_id_period_y_90648c57bf');
            $table->index(['scope', 'period_year', 'period_month'], 'serpro_usage_monthly_aggregates_scope_period_year__4b5f18606c');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_monthly_aggregates');
    }
};
