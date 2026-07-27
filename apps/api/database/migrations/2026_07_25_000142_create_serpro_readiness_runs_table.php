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
        Schema::create('serpro_readiness_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('scope', 20);
            $table->string('environment', 20);
            $table->bigInteger('serpro_contract_id')->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->bigInteger('client_id')->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->string('highest_gate', 40)->nullable();
            $table->string('result', 20);
            $table->boolean('live_evidence')->default(false);
            $table->string('trigger', 40)->default('MANUAL');
            $table->bigInteger('actor_user_id')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->jsonb('summary')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'environment']);
            $table->index(['scope', 'environment', 'result']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['serpro_contract_id'])->references(['id'])->on('serpro_contracts')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_readiness_runs');
    }
};
