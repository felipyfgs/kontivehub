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
        Schema::create('serpro_usage_budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scope', 20);
            $table->bigInteger('tenant_id')->nullable();
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('budget_kind', 40)->default('MONETARY');
            $table->bigInteger('limit_micros');
            $table->bigInteger('reserved_micros')->default(0);
            $table->bigInteger('consumed_micros')->default(0);
            $table->string('cycle_code', 40)->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->boolean('is_canary')->default(false);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['scope', 'environment', 'is_active']);
            $table->index(['tenant_id', 'is_active']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_budgets');
    }
};
