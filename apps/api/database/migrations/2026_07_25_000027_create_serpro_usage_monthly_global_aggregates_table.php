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
        Schema::create('serpro_usage_monthly_global_aggregates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('period_ym', 7);
            $table->string('consumption_class', 30);
            $table->bigInteger('quantity')->default(0);
            $table->bigInteger('estimated_cost_micros')->default(0);
            $table->timestampsTz();

            $table->unique(['period_ym', 'consumption_class'], 'serpro_usage_monthly_global_aggregates_period_ym_c_e2524f1a1a');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_monthly_global_aggregates');
    }
};
