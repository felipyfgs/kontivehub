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
        Schema::create('pgmei_debt_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->smallInteger('calendar_year');
            $table->string('debt_state', 32)->default('UNVERIFIED');
            $table->integer('items_count')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->timestampTz('last_valid_query_at')->nullable();
            $table->bigInteger('last_valid_observation_id')->nullable();
            $table->bigInteger('last_valid_run_id')->nullable();
            $table->bigInteger('last_valid_snapshot_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'calendar_year']);
            $table->index(['tenant_id', 'debt_state', 'last_valid_query_at'], 'pgmei_debt_projections_tenant_id_debt_state_last_v_fc9e7f53cd');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['last_valid_observation_id'])->references(['id'])->on('pgmei_debt_observations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_valid_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_valid_snapshot_id'])->references(['id'])->on('fiscal_snapshots')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pgmei_debt_projections');
    }
};
