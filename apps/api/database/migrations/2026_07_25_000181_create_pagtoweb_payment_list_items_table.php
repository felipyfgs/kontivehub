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
        Schema::create('pagtoweb_payment_list_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('observation_id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->char('document_digest', 64);
            $table->string('document_masked', 32);
            $table->string('document_type', 80)->nullable();
            $table->string('revenue_code', 8)->nullable();
            $table->string('revenue_description')->nullable();
            $table->date('paid_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('total_amount', 15)->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'client_id', 'observation_id'], 'pagtoweb_payment_list_items_tenant_id_client_id_ob_66272ef98a');
            $table->unique(['observation_id', 'document_digest'], 'pagtoweb_payment_list_items_observation_id_documen_2fe641b22a');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['observation_id'])->references(['id'])->on('pagtoweb_payment_list_observations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_payment_list_items');
    }
};
