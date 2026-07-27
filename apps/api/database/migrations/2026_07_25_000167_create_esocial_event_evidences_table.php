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
        Schema::create('esocial_event_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('establishment_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('fiscal_evidence_artifact_id')->nullable();
            $table->string('competence_period_key', 7);
            $table->string('event_code', 20);
            $table->string('event_version', 40)->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->string('establishment_cnpj', 14)->nullable();
            $table->string('content_sha256', 64);
            $table->string('vault_object_id', 26)->nullable();
            $table->string('content_type', 80)->default('application/json');
            $table->bigInteger('byte_size')->default(0);
            $table->string('source', 80)->default('esocial');
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('observed_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->boolean('is_quarantined')->default(false);
            $table->string('quarantine_reason', 120)->nullable();
            $table->timestampTz('quarantined_at')->nullable();

            $table->index(['tenant_id', 'client_id', 'competence_period_key', 'event_code'], 'esocial_event_evidences_tenant_id_client_id_compet_8a10a21fd3');
            $table->unique(['tenant_id', 'client_id', 'competence_period_key', 'event_code', 'content_sha256'], 'esocial_event_evidences_tenant_id_client_id_compet_9e1d287af7');
            $table->index(['tenant_id', 'establishment_id', 'competence_period_key'], 'esocial_event_evidences_tenant_id_establishment_id_f8e656c3c8');
            $table->index(['tenant_id', 'run_id']);
            $table->index(['tenant_id', 'is_quarantined']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esocial_event_evidences');
    }
};
