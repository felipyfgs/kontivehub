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
        Schema::create('tenant_serpro_onboarding_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('environment', 32);
            $table->string('status', 32)->default('incomplete');
            $table->string('idempotency_key', 64)->nullable();
            $table->string('last_step', 64)->nullable();
            $table->string('actionable_code', 64)->nullable();
            $table->string('actionable_message', 500)->nullable();
            $table->string('technical_code', 64)->nullable();
            $table->string('technical_message', 500)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('provisioning_started_at')->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('last_transition_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'environment']);
            $table->index(['status', 'environment']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_serpro_onboarding_states');
    }
};
