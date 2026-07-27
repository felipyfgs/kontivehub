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
        Schema::create('mailbox_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('mailbox_message_id');
            $table->string('external_id', 160)->nullable();
            $table->string('filename_sanitized')->nullable();
            $table->string('content_type', 80)->default('application/octet-stream');
            $table->string('vault_object_id', 26);
            $table->string('content_sha256', 64);
            $table->bigInteger('byte_size')->default(0);
            $table->string('sensitivity_class', 40)->default('FISCAL_RESTRICTED');
            $table->timestampTz('retention_until')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'mailbox_message_id']);
            $table->unique(['tenant_id', 'mailbox_message_id', 'content_sha256'], 'mailbox_attachments_tenant_id_mailbox_message_id_c_efb5edbb20');
            $table->foreign(['mailbox_message_id'])->references(['id'])->on('mailbox_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_attachments');
    }
};
