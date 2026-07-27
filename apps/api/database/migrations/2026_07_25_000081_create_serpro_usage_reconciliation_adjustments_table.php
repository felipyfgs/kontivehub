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
        Schema::create('serpro_usage_reconciliation_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('reconciliation_id');
            $table->bigInteger('tenant_id')->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('consumption_class', 30)->nullable();
            $table->bigInteger('amount_micros');
            $table->string('reason', 120);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['reconciliation_id', 'tenant_id'], 'serpro_usage_reconciliation_adjustments_reconcilia_33d5c00728');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reconciliation_id'], 'serpro_usage_reconciliation_adjustments_reconcilia_556d3d978f')->references(['id'])->on('serpro_usage_reconciliations')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_reconciliation_adjustments');
    }
};
