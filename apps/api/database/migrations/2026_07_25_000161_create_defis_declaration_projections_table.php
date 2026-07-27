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
        Schema::create('defis_declaration_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->smallInteger('calendar_year');
            $table->string('declaration_type', 1);
            $table->timestampTz('last_observed_at');
            $table->bigInteger('last_observation_id')->nullable();
            $table->bigInteger('last_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampsTz();
            $table->bigInteger('defis_declaration_reference_id')->nullable();

            $table->index(['tenant_id', 'client_id', 'last_observed_at'], 'defis_declaration_projections_tenant_id_client_id__a5a139e264');
            $table->unique(['tenant_id', 'client_id', 'calendar_year', 'declaration_type'], 'defis_declaration_projections_tenant_id_client_id__fa71d7134d');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['defis_declaration_reference_id'], 'defis_declaration_projections_defis_declaration_re_bc8e356dc8')->references(['id'])->on('defis_declaration_references')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_observation_id'])->references(['id'])->on('defis_declaration_observations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defis_declaration_projections');
    }
};
