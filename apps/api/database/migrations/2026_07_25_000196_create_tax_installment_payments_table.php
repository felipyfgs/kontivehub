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
        Schema::create('tax_installment_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('order_id');
            $table->bigInteger('parcel_id');
            $table->string('modality', 20);
            $table->string('status', 32)->default('UNKNOWN');
            $table->bigInteger('amount_cents')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->bigInteger('run_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'status']);
            $table->unique(['tenant_id', 'parcel_id', 'payment_ref']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['order_id'])->references(['id'])->on('tax_installment_orders')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_installment_payments');
    }
};
