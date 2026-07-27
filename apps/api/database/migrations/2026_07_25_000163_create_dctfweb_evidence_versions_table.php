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
        Schema::create('dctfweb_evidence_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('declaration_id')->nullable();
            $table->bigInteger('competence_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('evidence_artifact_id');
            $table->string('artifact_kind', 40);
            $table->integer('version');
            $table->string('content_sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->string('declaration_type', 30)->nullable();
            $table->string('source_version', 40)->nullable();
            $table->boolean('is_retification')->default(false);
            $table->timestampTz('observed_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'declaration_id', 'artifact_kind', 'is_current'], 'dctfweb_evidence_versions_tenant_id_declaration_id_cb0b8a826d');
            $table->unique(['tenant_id', 'declaration_id', 'artifact_kind', 'version'], 'dctfweb_evidence_versions_tenant_id_declaration_id_0d0b71a716');
            $table->index(['tenant_id', 'content_sha256']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['declaration_id'])->references(['id'])->on('dctfweb_declarations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dctfweb_evidence_versions');
    }
};
