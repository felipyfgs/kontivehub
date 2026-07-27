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
        Schema::create('ccmei_registration_status_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('status', 64);
            $table->boolean('enquadrado_mei');
            $table->string('situation', 32);
            $table->smallInteger('count');
            $table->timestampTz('last_valid_query_at');
            $table->bigInteger('last_observation_id')->nullable();
            $table->bigInteger('last_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id'], 'ccmei_registration_status_projections_tenant_id_cl_4b100dfca5');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['last_observation_id'], 'ccmei_registration_status_projections_last_observa_7c5e4ae989')->references(['id'])->on('ccmei_registration_status_observations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ccmei_registration_status_projections');
    }
};
