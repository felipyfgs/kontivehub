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
        Schema::create('pgdasd_artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('projection_id');
            $table->bigInteger('operation_id')->nullable();
            $table->bigInteger('fiscal_evidence_artifact_id');
            $table->string('declaration_number', 80)->nullable();
            $table->string('das_number', 80)->nullable();
            $table->string('kind', 40);
            $table->string('filename');
            $table->string('content_type', 80)->default('application/pdf');
            $table->timestampTz('observed_at');
            $table->bigInteger('source_run_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'kind', 'fiscal_evidence_artifact_id'], 'pgdasd_artifacts_tenant_id_client_id_kind_fiscal_e_f861db6136');
            $table->index(['tenant_id', 'client_id', 'projection_id', 'kind']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['fiscal_evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgdasd_artifacts');
    }
};
