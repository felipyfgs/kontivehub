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
        Schema::create('client_tax_regime_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('regime_code', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_system', 40)->nullable();
            $table->string('source_service', 80)->nullable();
            $table->bigInteger('source_run_id')->nullable();
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'effective_from'], 'client_tax_regime_periods_tenant_id_client_id_effe_d0a80bff74');
            $table->unique(['tenant_id', 'client_id', 'regime_code', 'effective_from'], 'client_tax_regime_periods_tenant_id_client_id_regi_d28e834084');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_tax_regime_periods');
    }
};
