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
        Schema::create('fiscal_document_quarantines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('sha256', 64);
            $table->string('vault_object_id', 26);
            $table->integer('byte_size');
            $table->string('access_key', 50)->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('recipient_cnpj', 14)->nullable();
            $table->string('model', 5)->nullable();
            $table->string('schema_family', 40)->nullable();
            $table->string('reason', 60);
            $table->string('source', 40);
            $table->string('channel', 40)->nullable();
            $table->bigInteger('nsu')->nullable();
            $table->bigInteger('tenant_distribution_cursor_id')->nullable();
            $table->bigInteger('document_import_batch_item_id')->nullable();
            $table->string('resolution_status', 20)->default('OPEN');
            $table->bigInteger('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_code', 60)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->bigInteger('promoted_dfe_document_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'resolution_status', 'reason'], 'fiscal_document_quarantines_tenant_id_resolution_s_0a520cda2d');
            $table->unique(['tenant_id', 'sha256', 'source', 'nsu']);
            $table->foreign(['tenant_distribution_cursor_id'], 'fiscal_document_quarantines_tenant_distribution_cu_ecfe43492a')->references(['id'])->on('tenant_distribution_cursors')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['promoted_dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['resolved_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['document_import_batch_item_id'], 'fiscal_document_quarantines_document_import_batch__bade32f8a1')->references(['id'])->on('document_import_batch_items')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_quarantines');
    }
};
