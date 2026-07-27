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
        Schema::create('tax_installment_parcels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('order_id');
            $table->string('modality', 20);
            $table->string('parcel_key', 40);
            $table->smallInteger('parcel_number')->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('source_status', 80)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->boolean('document_available')->default(false);
            $table->string('payment_status', 30)->default('NONE');
            $table->timestampTz('paid_at')->nullable();
            $table->bigInteger('payment_id')->nullable();
            $table->bigInteger('tax_guide_id')->nullable()->index();
            $table->string('logical_key', 160);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'logical_key']);
            $table->index(['tenant_id', 'client_id', 'status', 'due_at']);
            $table->index(['tenant_id', 'modality']);
            $table->unique(['tenant_id', 'order_id', 'parcel_key']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['order_id'])->references(['id'])->on('tax_installment_orders')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tax_guide_id'])->references(['id'])->on('tax_guides')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_installment_parcels');
    }
};
