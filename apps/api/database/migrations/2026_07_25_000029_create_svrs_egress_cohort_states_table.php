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
        Schema::create('svrs_egress_cohort_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cohort_id', 64)->unique();
            $table->string('state', 20)->default('closed');
            $table->string('cause', 60)->nullable();
            $table->smallInteger('tier')->default(0);
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('next_probe_at')->nullable();
            $table->string('canary_access_key_hash', 64)->nullable();
            $table->string('canary_key_mask', 20)->nullable();
            $table->string('template_fingerprint', 64)->nullable();
            $table->string('active_deployment_id', 64)->nullable();
            $table->timestampTz('last_exchange_at')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index(['state', 'next_probe_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('svrs_egress_cohort_states');
    }
};
