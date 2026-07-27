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
        Schema::create('serpro_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('operation_key', 120)->unique();
            $table->string('label')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('consumption_class', 30)->nullable();
            $table->jsonb('metadata_sanitized')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_operations');
    }
};
