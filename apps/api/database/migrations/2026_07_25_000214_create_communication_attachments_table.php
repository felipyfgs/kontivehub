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
        Schema::create('communication_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('message_id');
            $table->string('object_id', 26)->unique();
            $table->text('original_name_encrypted')->nullable();
            $table->string('mime_type', 160);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('disposition', 16)->default('attachment');
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->jsonb('storage_context')->nullable();

            $table->index(['tenant_id', 'sha256']);
            $table->index(['tenant_id', 'message_id']);
            $table->foreign(['message_id'])->references(['id'])->on('communication_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_attachments');
    }
};
