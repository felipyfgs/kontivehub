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
        Schema::create('sicalc_revenue_support_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('revenue_code', 16);
            $table->string('description');
            $table->jsonb('extensions');
            $table->smallInteger('extension_count');
            $table->char('digest', 64);
            $table->timestampTz('observed_at');
            $table->bigInteger('source_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'client_id', 'revenue_code', 'digest'], 'sicalc_revenue_support_observations_tenant_id_clie_a5e036c479');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['source_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sicalc_revenue_support_observations');
    }
};
