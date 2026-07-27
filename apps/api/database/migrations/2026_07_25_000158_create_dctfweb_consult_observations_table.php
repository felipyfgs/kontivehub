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
        Schema::create('dctfweb_consult_observations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('declaration_id')->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->string('category', 40)->default('GERAL_MENSAL');
            $table->string('period_key', 20);
            $table->string('ano_pa', 4);
            $table->string('mes_pa', 2);
            $table->string('outcome', 40);
            $table->string('provenance', 40)->nullable();
            $table->string('declaration_state', 40)->nullable();
            $table->boolean('productive')->default(false);
            $table->boolean('document_stored')->default(false);
            $table->string('reason', 120)->nullable();
            $table->string('sanitized_message')->nullable();
            $table->timestampTz('observed_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'client_id', 'category', 'period_key'], 'dctfweb_consult_observations_tenant_id_client_id_c_c76bd0dc26');
            $table->index(['tenant_id', 'client_id', 'period_key', 'observed_at'], 'dctfweb_consult_observations_tenant_id_client_id_p_720e5b89c2');
            $table->index(['tenant_id', 'run_id']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['declaration_id'])->references(['id'])->on('dctfweb_declarations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dctfweb_consult_observations');
    }
};
