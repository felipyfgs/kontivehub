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
        Schema::create('serpro_quantity_usage_limits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('environment', 20)->unique();
            $table->smallInteger('cycle_start_day')->default(1);
            $table->smallInteger('alert_percent')->default(80);
            $table->bigInteger('global_limit_quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_quantity_usage_limits');
    }
};
