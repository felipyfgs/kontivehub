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
        Schema::create('pagtoweb_payment_list_projections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('last_observation_id')->nullable();
            $table->bigInteger('last_run_id')->nullable();
            $table->timestampTz('last_valid_query_at');
            $table->string('source_provenance', 32);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['last_observation_id'])->references(['id'])->on('pagtoweb_payment_list_observations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_payment_list_projections');
    }
};
