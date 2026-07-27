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
        Schema::create('serpro_circuit_breaker_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scope_key', 120)->unique();
            $table->string('dependency', 40)->default('SERPRO');
            $table->string('solution_code', 40)->nullable();
            $table->string('state', 20)->default('closed');
            $table->integer('failures')->default(0);
            $table->integer('half_open_probes')->default(0);
            $table->timestampTz('open_until')->nullable();
            $table->string('last_reason', 200)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['dependency', 'solution_code']);
            $table->index(['state', 'open_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_circuit_breaker_states');
    }
};
