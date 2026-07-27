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
        Schema::create('defis_declaration_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->smallInteger('calendar_year');
            $table->string('declaration_type', 1);
            $table->string('digest', 64);
            $table->timestampTz('observed_at');
            $table->bigInteger('source_run_id')->nullable();
            $table->string('source_provenance', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'client_id', 'digest'], 'defis_declaration_observations_tenant_id_client_id_d19101c33a');
            $table->index(['tenant_id', 'client_id', 'observed_at'], 'defis_declaration_observations_tenant_id_client_id_5e7880320f');
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
        Schema::dropIfExists('defis_declaration_observations');
    }
};
