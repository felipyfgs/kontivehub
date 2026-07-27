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
        Schema::create('serpro_price_tiers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('price_version_id');
            $table->string('consumption_class', 30);
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('operation_code', 80)->nullable();
            $table->integer('min_quantity')->default(1);
            $table->integer('max_quantity')->nullable();
            $table->bigInteger('unit_cost_micros');
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['price_version_id', 'consumption_class', 'system_code', 'service_code', 'operation_code'], 'serpro_price_tiers_price_version_id_consumption_cl_861cc4d5c8');
            $table->foreign(['price_version_id'])->references(['id'])->on('serpro_price_versions')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_price_tiers');
    }
};
