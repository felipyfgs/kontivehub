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
        Schema::create('tax_obligation_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('obligation_definition_id');
            $table->bigInteger('obligation_version_id')->nullable();
            $table->bigInteger('calendar_version_id')->nullable();
            $table->bigInteger('competence_id')->nullable();
            $table->string('period_key', 20);
            $table->smallInteger('period_year');
            $table->smallInteger('period_month')->nullable();
            $table->string('applicability', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('delivery_status', 30)->default('UNKNOWN');
            $table->timestampTz('due_at')->nullable();
            $table->jsonb('due_rule_snapshot')->nullable();
            $table->jsonb('due_history')->nullable();
            $table->text('applicability_basis')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('conclusive_evidence_id')->nullable();
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->timestampTz('last_valid_query_at')->nullable();
            $table->bigInteger('last_valid_run_id')->nullable();
            $table->bigInteger('last_valid_snapshot_id')->nullable();
            $table->string('pgdasd_declaration_state', 40)->nullable();
            $table->timestampTz('pgdasd_last_productive_consulted_at')->nullable();
            $table->bigInteger('pgdasd_last_declaration_operation_id')->nullable();
            $table->bigInteger('pgdasd_latest_rbt12_projection_id')->nullable();
            $table->string('pgdasd_calendar_version_code', 60)->nullable();
            $table->boolean('pgdasd_calendar_verified')->default(false);
            $table->string('dctfweb_declaration_state', 40)->nullable();
            $table->timestampTz('dctfweb_last_productive_consulted_at')->nullable();
            $table->bigInteger('dctfweb_last_declaration_id')->nullable();
            $table->string('dctfweb_calendar_version_code', 60)->nullable();
            $table->boolean('dctfweb_calendar_verified')->default(false);
            $table->string('dctfweb_category', 40)->nullable();

            $table->index(['tenant_id', 'applicability', 'delivery_status'], 'tax_obligation_projections_tenant_id_applicability_df8996a13d');
            $table->unique(['tenant_id', 'client_id', 'obligation_definition_id', 'period_key'], 'tax_obligation_projections_tenant_id_client_id_obl_f227e7f08a');
            $table->index(['tenant_id', 'client_id', 'situation']);
            $table->index(['tenant_id', 'last_valid_query_at']);
            $table->index(['tenant_id', 'is_open', 'due_at']);
            $table->index(['tenant_id', 'period_year', 'period_month'], 'tax_obligation_projections_tenant_id_period_year_p_6aa5b5a0a4');
            $table->foreign(['calendar_version_id'])->references(['id'])->on('tax_deadline_calendar_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['dctfweb_last_declaration_id'])->references(['id'])->on('dctfweb_declarations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_valid_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_valid_snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['obligation_definition_id'])->references(['id'])->on('tax_obligation_definitions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['obligation_version_id'])->references(['id'])->on('tax_obligation_versions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_obligation_projections');
    }
};
