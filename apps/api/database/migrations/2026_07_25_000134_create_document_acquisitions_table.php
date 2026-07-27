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
        Schema::create('document_acquisitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('dfe_document_id')->unique();
            $table->string('access_key', 50)->nullable();
            $table->string('source', 40);
            $table->string('channel', 40)->nullable();
            $table->string('sha256', 64);
            $table->boolean('is_canonical')->default(true);
            $table->boolean('bytes_diverge_from_canonical')->default(false);
            $table->string('quarantine_reason')->nullable();
            $table->bigInteger('establishment_id')->nullable();
            $table->bigInteger('outbound_retrieval_request_id')->nullable();
            $table->bigInteger('outbound_number_state_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->bigInteger('nsu')->nullable();
            $table->bigInteger('tenant_distribution_cursor_id')->nullable();
            $table->bigInteger('document_import_batch_item_id')->nullable()->index();
            $table->string('artifact_quality', 40)->nullable();
            $table->string('signature_result', 40)->nullable();

            $table->unique(['document_import_batch_item_id', 'sha256'], 'document_acquisitions_document_import_batch_item_i_837ebfddee');
            $table->unique(['outbound_retrieval_request_id', 'sha256'], 'document_acquisitions_outbound_retrieval_request_i_1e17df2d24');
            $table->index(['tenant_id', 'source', 'channel', 'nsu']);
            $table->unique(['dfe_document_id', 'source', 'sha256']);
            $table->index(['tenant_id', 'access_key']);
            $table->index(['tenant_id', 'source']);
            $table->index(['tenant_id', 'nsu']);
            $table->index(['tenant_id', 'artifact_quality']);
            $table->index(['tenant_id', 'source', 'channel']);
            $table->foreign(['dfe_document_id'])->references(['id'])->on('dfe_documents')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['document_import_batch_item_id'])->references(['id'])->on('document_import_batch_items')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['outbound_retrieval_request_id'])->references(['id'])->on('outbound_retrieval_requests')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_distribution_cursor_id'])->references(['id'])->on('tenant_distribution_cursors')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['outbound_number_state_id'])->references(['id'])->on('outbound_number_states')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_acquisitions');
    }
};
