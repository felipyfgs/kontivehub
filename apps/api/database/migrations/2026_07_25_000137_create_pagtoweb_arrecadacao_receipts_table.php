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
        Schema::create('pagtoweb_arrecadacao_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('receipt_vault_object_id', 26);
            $table->char('receipt_sha256', 64);
            $table->string('receipt_mime_type', 100);
            $table->integer('receipt_byte_size');
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'observed_at'], 'pagtoweb_arrecadacao_receipts_tenant_id_client_id__1784b60f42');
            $table->unique(['tenant_id', 'client_id', 'receipt_sha256'], 'pagtoweb_arrecadacao_receipts_tenant_id_client_id__2abc986a92');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagtoweb_arrecadacao_receipts');
    }
};
