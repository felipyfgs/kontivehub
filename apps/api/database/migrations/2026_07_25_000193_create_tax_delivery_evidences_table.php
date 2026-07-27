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
        Schema::create('tax_delivery_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('projection_id');
            $table->string('kind', 40);
            $table->string('protocol_number', 80)->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->boolean('is_conclusive')->default(false);
            $table->string('source', 80);
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('observed_at');
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->string('payload_digest', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'projection_id', 'is_conclusive'], 'tax_delivery_evidences_tenant_id_projection_id_is__6a86547afc');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_delivery_evidences');
    }
};
