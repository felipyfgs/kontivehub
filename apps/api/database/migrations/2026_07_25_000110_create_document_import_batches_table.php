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
        Schema::create('document_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->bigInteger('tenant_id');
            $table->bigInteger('created_by');
            $table->bigInteger('client_id')->nullable();
            $table->bigInteger('establishment_id')->nullable();
            $table->string('status', 40)->default('UPLOADED');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('selection_digest', 64)->nullable();
            $table->integer('file_count')->default(0);
            $table->integer('item_count')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('unmatched_count')->default(0);
            $table->integer('invalid_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('quarantined_count')->default(0);
            $table->bigInteger('compressed_bytes')->default(0);
            $table->bigInteger('uncompressed_bytes')->default(0);
            $table->string('spool_vault_object_id', 26)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('spool_expires_at')->nullable();
            $table->jsonb('quotas')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'selection_digest']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['establishment_id'])->references(['id'])->on('establishments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_import_batches');
    }
};
