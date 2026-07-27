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
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->string('deadline_timezone', 64)->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('serpro_segregation_class', 40)->nullable();
            $table->string('lifecycle_status', 32)->default('ACTIVE')->index();
            $table->boolean('communication_enabled')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
