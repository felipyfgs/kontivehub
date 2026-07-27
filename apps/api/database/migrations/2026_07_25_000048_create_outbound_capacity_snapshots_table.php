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
        Schema::create('outbound_capacity_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id')->nullable();
            $table->string('competence', 7);
            $table->string('scope', 40)->default('COHORT');
            $table->string('root_cnpj', 8)->nullable();
            $table->string('model', 5)->nullable();
            $table->integer('demand_exchanges')->default(0);
            $table->integer('safe_capacity_exchanges')->default(0);
            $table->integer('nominal_capacity_exchanges')->default(0);
            $table->integer('slack_exchanges')->default(0);
            $table->decimal('slack_ratio', 8, 4)->nullable();
            $table->integer('items_total')->default(0);
            $table->integer('items_planned')->default(0);
            $table->integer('items_attention')->default(0);
            $table->integer('items_contingency')->default(0);
            $table->integer('items_overdue')->default(0);
            $table->integer('items_captured')->default(0);
            $table->integer('items_capacity_at_risk')->default(0);
            $table->timestampTz('estimated_completion_at')->nullable();
            $table->timestampTz('target_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->boolean('at_risk')->default(false);
            $table->jsonb('metrics')->nullable();
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->index(['competence', 'at_risk']);
            $table->index(['tenant_id', 'competence', 'calculated_at'], 'outbound_capacity_snapshots_tenant_id_competence_c_d300d7511a');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbound_capacity_snapshots');
    }
};
