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
        Schema::create('communication_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('conversation_id');
            $table->bigInteger('identity_id');
            $table->bigInteger('reply_to_message_id')->nullable();
            $table->bigInteger('author_membership_id')->nullable();
            $table->bigInteger('client_communication_dispatch_id')->nullable();
            $table->string('direction', 20);
            $table->string('kind', 20);
            $table->string('source', 32)->default('HUMAN');
            $table->string('status', 32)->default('QUEUED');
            $table->text('body_encrypted')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('gateway_event_id', 128)->nullable();
            $table->char('content_digest', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestampsTz();
            $table->string('provider_type', 80)->nullable();
            $table->text('content_encrypted')->nullable();
            $table->timestampTz('played_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->unique(['inbox_id', 'gateway_event_id']);
            $table->unique(['inbox_id', 'provider_message_id']);
            $table->index(['tenant_id', 'provider_type']);
            $table->index(['tenant_id', 'revoked_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'conversation_id', 'occurred_at'], 'communication_messages_tenant_id_conversation_id_o_025092e560');
            $table->foreign(['author_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_messages');
    }
};
