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
        Schema::create('tax_installment_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('snapshot_id')->nullable();
            $table->string('modality', 20);
            $table->string('regime', 10);
            $table->string('external_order_id', 80);
            $table->string('situation', 40)->default('UNKNOWN');
            $table->string('source_status', 80)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('consolidated_at')->nullable();
            $table->integer('parcel_count')->nullable();
            $table->bigInteger('total_amount_cents')->nullable();
            $table->string('source_system', 40)->default('INTEGRA_PARCELAMENTO');
            $table->string('source_service', 80);
            $table->string('source_operation', 80)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'modality', 'external_order_id'], 'tax_installment_orders_tenant_id_client_id_modalit_d0dcdb2f49');
            $table->index(['tenant_id', 'client_id', 'modality']);
            $table->index(['tenant_id', 'situation']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
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
        Schema::dropIfExists('tax_installment_orders');
    }
};
