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
        Schema::create('defis_specific_declaration_artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('defis_declaration_reference_id');
            $table->string('kind', 16);
            $table->bigInteger('fiscal_evidence_artifact_id');
            $table->bigInteger('source_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->string('filename', 160);
            $table->string('content_type', 100);
            $table->string('digest', 64);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'defis_declaration_reference_id', 'kind', 'digest'], 'defis_specific_declaration_artifacts_tenant_id_cli_d54f5b39ca');
            $table->index(['tenant_id', 'client_id', 'defis_declaration_reference_id'], 'defis_specific_declaration_artifacts_tenant_id_cli_371c5b89b7');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['defis_declaration_reference_id'], 'defis_specific_declaration_artifacts_defis_declara_9b2f79c2de')->references(['id'])->on('defis_declaration_references')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_evidence_artifact_id'], 'defis_specific_declaration_artifacts_fiscal_eviden_8e07601db8')->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defis_specific_declaration_artifacts');
    }
};
