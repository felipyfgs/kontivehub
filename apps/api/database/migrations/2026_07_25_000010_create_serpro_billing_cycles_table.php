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
        Schema::create('serpro_billing_cycles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cycle_code', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('label', 120)->nullable();
            $table->string('status', 32)->default('OPEN');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_billing_cycles');
    }
};
