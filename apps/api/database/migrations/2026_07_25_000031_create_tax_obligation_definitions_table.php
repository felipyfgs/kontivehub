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
        Schema::create('tax_obligation_definitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('fiscal_category_code', 60)->nullable();
            $table->string('module_key', 40)->nullable();
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('period_granularity', 20)->default('MONTHLY');
            $table->string('default_timezone', 64)->default('America/Sao_Paulo');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->jsonb('supported_operations')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['module_key', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_obligation_definitions');
    }
};
