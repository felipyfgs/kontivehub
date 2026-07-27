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
        Schema::create('serpro_usage_reconciliations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->smallInteger('period_year');
            $table->smallInteger('period_month');
            $table->string('official_reference', 120)->nullable();
            $table->string('official_source', 80)->nullable();
            $table->bigInteger('official_total_cost_micros');
            $table->bigInteger('internal_total_estimated_cost_micros')->default(0);
            $table->bigInteger('difference_micros')->default(0);
            $table->string('status', 32);
            $table->string('difference_cause', 120)->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('imported_by_user_id')->nullable();
            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();
            $table->string('cycle_code', 40)->nullable();
            $table->string('period_kind', 20)->default('CALENDAR_MONTH');

            $table->unique(['period_year', 'period_month', 'official_reference'], 'serpro_usage_reconciliations_period_year_period_mo_5a5bf87581');
            $table->index(['period_year', 'period_month', 'status'], 'serpro_usage_reconciliations_period_year_period_mo_7fbbdc3eea');
            $table->foreign(['imported_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_reconciliations');
    }
};
