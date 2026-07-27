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
        Schema::create('tax_guides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('establishment_id')->nullable();
            $table->string('operation_key', 120);
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80)->default('EMITIR_GUIA');
            $table->string('competence_period_key', 20)->nullable();
            $table->string('debit_ref', 120)->nullable();
            $table->string('logical_key', 180);
            $table->bigInteger('current_version_id')->nullable();
            $table->enum('payment_status', ['UNKNOWN', 'PENDING', 'PAID', 'OVERDUE', 'CANCELLED', 'PARTIAL', 'NOT_APPLICABLE', 'CONFIRMED', 'NOT_CONFIRMED'])->default('UNKNOWN');
            $table->timestampTz('payment_confirmed_at')->nullable();
            $table->string('payment_source', 80)->nullable();
            $table->string('payment_external_id', 160)->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->default('BRL');
            $table->timestampTz('due_at')->nullable();
            $table->string('identifier_code', 120)->nullable();
            $table->bigInteger('created_by')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'payment_status']);
            $table->index(['tenant_id', 'due_at']);
            $table->unique(['tenant_id', 'logical_key']);
            $table->index(['tenant_id', 'system_code', 'service_code']);
            $table->index(['tenant_id', 'operation_key']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_guides');
    }
};
