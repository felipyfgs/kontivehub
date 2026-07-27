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
        Schema::create('fgts_competence_statuses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('establishment_id')->nullable();
            $table->bigInteger('fiscal_competence_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('snapshot_id')->nullable();
            $table->string('competence_period_key', 7);
            $table->string('closure_status', 20)->default('UNKNOWN');
            $table->string('totalization_status', 20)->default('UNKNOWN');
            $table->string('guide_status', 20)->default('UNSUPPORTED');
            $table->string('payment_status', 20)->default('UNSUPPORTED');
            $table->string('coverage', 30)->default('PARTIAL');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->timestampTz('closure_observed_at')->nullable();
            $table->timestampTz('totalizer_observed_at')->nullable();
            $table->timestampTz('totalizer_due_by')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->jsonb('limitations')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->boolean('is_quarantined')->default(false);
            $table->string('quarantine_reason', 120)->nullable();
            $table->timestampTz('quarantined_at')->nullable();

            $table->unique(['tenant_id', 'client_id', 'establishment_id', 'competence_period_key'], 'fgts_competence_statuses_tenant_id_client_id_estab_c7f53a9806');
            $table->index(['tenant_id', 'client_id', 'situation']);
            $table->index(['tenant_id', 'competence_period_key']);
            $table->index(['tenant_id', 'is_quarantined']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['fiscal_competence_id'])->references(['id'])->on('fiscal_competences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fgts_competence_statuses');
    }
};
