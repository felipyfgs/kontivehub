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
        Schema::create('sicalc_revenue_support_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('revenue_code', 16);
            $table->string('description');
            $table->jsonb('extensions');
            $table->smallInteger('extension_count');
            $table->timestampTz('last_valid_query_at');
            $table->bigInteger('last_observation_id')->nullable();
            $table->bigInteger('last_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'revenue_code'], 'sicalc_revenue_support_projections_tenant_id_clien_ce5437f3ad');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['last_observation_id'])->references(['id'])->on('sicalc_revenue_support_observations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sicalc_revenue_support_projections');
    }
};
