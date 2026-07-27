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
        Schema::create('fiscal_guide_stubs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('run_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('regime_family', 40);
            $table->string('period_key', 20);
            $table->string('document_number', 80)->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14)->nullable();
            $table->string('emission_status', 30)->default('STUB');
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->boolean('is_external_call')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->boolean('is_quarantined')->default(false);
            $table->string('quarantine_reason', 120)->nullable();
            $table->timestampTz('quarantined_at')->nullable();

            $table->index(['tenant_id', 'client_id', 'period_key']);
            $table->index(['tenant_id', 'payment_status']);
            $table->index(['tenant_id', 'is_quarantined']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_guide_stubs');
    }
};
