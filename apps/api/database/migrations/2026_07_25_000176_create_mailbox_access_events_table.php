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
        Schema::create('mailbox_access_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('mailbox_message_id');
            $table->bigInteger('mailbox_attachment_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->string('action', 40);
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'mailbox_message_id', 'created_at'], 'mailbox_access_events_tenant_id_mailbox_message_id_1c98420247');
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->foreign(['mailbox_attachment_id'])->references(['id'])->on('mailbox_attachments')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['mailbox_message_id'])->references(['id'])->on('mailbox_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_access_events');
    }
};
