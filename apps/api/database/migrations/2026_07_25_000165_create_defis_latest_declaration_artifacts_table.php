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
        Schema::create('defis_latest_declaration_artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->smallInteger('calendar_year');
            $table->string('kind', 16);
            $table->bigInteger('fiscal_evidence_artifact_id');
            $table->bigInteger('source_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->string('filename', 160);
            $table->string('content_type', 100);
            $table->string('digest', 64);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'calendar_year', 'kind', 'digest'], 'defis_latest_declaration_artifacts_tenant_id_clien_2c1c0fa77a');
            $table->index(['tenant_id', 'client_id', 'calendar_year'], 'defis_latest_declaration_artifacts_tenant_id_clien_e613977aca');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_evidence_artifact_id'], 'defis_latest_declaration_artifacts_fiscal_evidence_77c086802e')->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defis_latest_declaration_artifacts');
    }
};
