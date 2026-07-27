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
        Schema::create('serpro_price_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('version_code', 40)->unique();
            $table->string('name', 120);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('currency', 3)->default('BRL');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->string('source_url', 500)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('source_revision', 80)->nullable();
            $table->string('eligibility', 30)->default('SHADOW');
            $table->boolean('authorizes_production')->default(false);
            $table->string('billing_cycle_kind', 20)->default('D21_D20');

            $table->index(['is_active', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_price_versions');
    }
};
