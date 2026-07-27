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
        Schema::create('pgmei_debt_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->smallInteger('calendar_year');
            $table->string('debt_state', 32);
            $table->string('digest', 64);
            $table->integer('items_count')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->timestampTz('observed_at');
            $table->bigInteger('source_run_id')->nullable();
            $table->bigInteger('source_snapshot_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'client_id', 'calendar_year', 'digest'], 'pgmei_debt_observations_tenant_id_client_id_calend_251d228eef');
            $table->index(['tenant_id', 'client_id', 'calendar_year', 'observed_at'], 'pgmei_debt_observations_tenant_id_client_id_calend_cd7b6133c0');
            $table->unique(['tenant_id', 'source_run_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['source_snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgmei_debt_observations');
    }
};
