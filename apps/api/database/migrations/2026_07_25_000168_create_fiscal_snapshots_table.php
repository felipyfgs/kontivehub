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
        Schema::create('fiscal_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('run_id');
            $table->bigInteger('client_id');
            $table->bigInteger('competence_id')->nullable();
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80)->nullable();
            $table->string('situation', 30);
            $table->string('coverage', 30);
            $table->integer('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->jsonb('normalized')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->string('source_provenance', 20)->nullable()->index();
            $table->string('verification_state', 20)->nullable();
            $table->string('operation_key', 120)->nullable();

            $table->index(['tenant_id', 'client_id', 'system_code', 'service_code', 'is_current'], 'fiscal_snapshots_tenant_id_client_id_system_code_s_46d7a07ec7');
            $table->index(['tenant_id', 'competence_id', 'is_current']);
            $table->index(['tenant_id', 'run_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_snapshots');
    }
};
