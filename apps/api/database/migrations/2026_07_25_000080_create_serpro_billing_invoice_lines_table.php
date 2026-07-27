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
        Schema::create('serpro_billing_invoice_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cycle_code', 40);
            $table->bigInteger('reconciliation_id')->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->string('functional_route', 40)->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->string('request_tag', 32)->nullable()->index();
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('operation_code', 80)->nullable();
            $table->string('consumption_class', 30)->nullable();
            $table->integer('quantity')->default(1);
            $table->bigInteger('official_cost_micros')->default(0);
            $table->bigInteger('internal_cost_micros')->nullable();
            $table->bigInteger('difference_micros')->default(0);
            $table->string('line_status', 30)->default('IMPORTED');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['cycle_code', 'tenant_id']);
            $table->index(['functional_route', 'http_status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['reconciliation_id'])->references(['id'])->on('serpro_usage_reconciliations')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_billing_invoice_lines');
    }
};
