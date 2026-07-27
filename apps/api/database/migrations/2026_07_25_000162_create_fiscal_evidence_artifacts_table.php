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
        Schema::create('fiscal_evidence_artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('run_id');
            $table->string('vault_object_id', 26);
            $table->string('content_sha256', 64);
            $table->string('content_type', 80)->default('application/json');
            $table->bigInteger('byte_size')->default(0);
            $table->string('source', 80);
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('retention_until')->nullable();
            $table->boolean('is_immutable')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->string('source_provenance', 20)->nullable()->index();
            $table->string('verification_state', 20)->nullable();
            $table->string('operation_key', 120)->nullable();

            $table->index(['tenant_id', 'retention_until']);
            $table->index(['tenant_id', 'run_id']);
            $table->unique(['tenant_id', 'content_sha256', 'run_id'], 'fiscal_evidence_artifacts_tenant_id_content_sha256_a8b61a3d5f');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_evidence_artifacts');
    }
};
