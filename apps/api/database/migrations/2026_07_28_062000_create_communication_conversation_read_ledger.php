<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversation_read_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('conversation_id');
            $table->unsignedBigInteger('version')->default(0);
            $table->bigInteger('last_read_through_message_id')->nullable();
            $table->bigInteger('updated_by_user_id')->nullable();
            $table->bigInteger('updated_by_membership_id')->nullable();
            $table->string('last_action', 24)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'conversation_id'], 'communication_read_states_tenant_conversation_uidx');
            $table->index(['tenant_id', 'inbox_id', 'version'], 'communication_read_states_tenant_inbox_version_idx');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inbox_id')->references('id')->on('communication_inboxes')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
            $table->foreign('last_read_through_message_id')->references('id')->on('communication_messages')->nullOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_membership_id')->references('id')->on('tenant_memberships')->nullOnDelete();
        });

        Schema::create('communication_conversation_unread_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('conversation_id');
            $table->bigInteger('message_id');
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'conversation_id', 'message_id'],
                'communication_unread_tenant_conversation_message_uidx',
            );
            $table->unique(['tenant_id', 'message_id'], 'communication_unread_tenant_message_uidx');
            $table->index(
                ['tenant_id', 'inbox_id', 'conversation_id', 'message_id'],
                'communication_unread_tenant_inbox_conversation_idx',
            );
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inbox_id')->references('id')->on('communication_inboxes')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('communication_messages')->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_read_states
            ADD CONSTRAINT communication_read_states_version_chk CHECK (version >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_unread_messages');
        Schema::dropIfExists('communication_conversation_read_states');
    }
};
