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
        Schema::create('document_import_batch_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('document_import_batch_id');
            $table->integer('item_index')->default(0);
            $table->string('source_name');
            $table->string('entry_name')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('access_key', 50)->nullable();
            $table->string('model', 5)->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            $table->bigInteger('establishment_id')->nullable();
            $table->bigInteger('dfe_document_id')->nullable();
            $table->string('status', 40)->default('PENDING');
            $table->string('result_code', 60)->nullable();
            $table->string('result_message', 500)->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('byte_size')->nullable();
            $table->string('spool_vault_object_id', 26)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['document_import_batch_id', 'item_index'], 'document_import_batch_items_document_import_batch__c3d9b28ee1');
            $table->index(['document_import_batch_id', 'status'], 'document_import_batch_items_document_import_batch__12ecd7ffc2');
            $table->index(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['document_import_batch_id'])->references(['id'])->on('document_import_batches')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_import_batch_items');
    }
};
