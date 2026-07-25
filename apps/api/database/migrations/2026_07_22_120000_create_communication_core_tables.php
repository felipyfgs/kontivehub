<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_inboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('session_id', 128)->unique();
            $table->text('address_encrypted')->nullable();
            $table->char('address_hash', 64)->nullable();
            $table->string('address_masked', 40)->nullable();
            $table->string('status', 32)->default('DISCONNECTED');
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->foreignId('work_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->json('settings')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'name'], 'comm_inboxes_office_name_uq');
            $table->unique(['office_id', 'address_hash'], 'comm_inboxes_office_address_uq');
            $table->index(['office_id', 'status', 'is_enabled'], 'comm_inboxes_office_status_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX comm_inboxes_one_default_per_office
             ON communication_inboxes (office_id)
             WHERE is_default = true',
        );

        Schema::create('communication_inbox_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('communication_inboxes')->cascadeOnDelete();
            $table->foreignId('office_membership_id')->constrained('office_user')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['inbox_id', 'office_membership_id'], 'comm_inbox_members_member_uq');
            $table->index(['office_id', 'office_membership_id', 'is_active'], 'comm_inbox_members_access_idx');
        });

        Schema::create('communication_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160)->nullable();
            $table->boolean('is_provisional')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'is_provisional', 'is_active'], 'comm_contacts_office_state_idx');
        });

        Schema::create('communication_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('communication_contacts')->cascadeOnDelete();
            $table->string('channel', 20)->default('WHATSAPP');
            $table->text('address_encrypted')->nullable();
            $table->char('address_hash', 64);
            $table->string('address_masked', 40);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'channel', 'address_hash'], 'comm_identities_office_channel_hash_uq');
            $table->index(['office_id', 'contact_id', 'is_active'], 'comm_identities_contact_idx');
        });

        Schema::create('communication_identity_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained('communication_identities')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->boolean('receives_automatic')->default(true);
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'receives_automatic'], 'comm_identity_links_client_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX comm_identity_links_contact_uq
             ON communication_identity_links (identity_id, client_id, client_contact_id)
             WHERE client_contact_id IS NOT NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX comm_identity_links_client_uq
             ON communication_identity_links (identity_id, client_id)
             WHERE client_contact_id IS NULL',
        );

        Schema::create('communication_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('communication_inboxes')->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained('communication_identities')->restrictOnDelete();
            $table->string('status', 32)->default('OPEN');
            $table->foreignId('work_department_id')->nullable()->constrained('work_departments')->nullOnDelete();
            $table->foreignId('assignee_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestampTz('snoozed_until')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampTz('purged_at')->nullable();
            $table->char('tombstone_digest', 64)->nullable();
            $table->timestamps();

            $table->index(['office_id', 'inbox_id', 'status', 'last_message_at'], 'comm_conversations_queue_idx');
            $table->index(['office_id', 'assignee_membership_id', 'status'], 'comm_conversations_assignee_idx');
            $table->index(['office_id', 'work_department_id', 'status'], 'comm_conversations_department_idx');
        });

        DB::statement(
            "CREATE UNIQUE INDEX comm_conversations_one_active_uq
             ON communication_conversations (inbox_id, identity_id)
             WHERE status <> 'RESOLVED'",
        );

        Schema::create('communication_conversation_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'client_id'], 'comm_conversation_clients_uq');
            $table->index(['office_id', 'client_id'], 'comm_conversation_clients_client_idx');
        });

        Schema::create('communication_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->constrained('communication_inboxes')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained('communication_identities')->restrictOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('communication_messages')->nullOnDelete();
            $table->foreignId('author_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->foreignId('client_communication_dispatch_id')->nullable()->constrained('client_communication_dispatches')->nullOnDelete();
            $table->string('direction', 20);
            $table->string('kind', 20);
            $table->string('source', 32)->default('HUMAN');
            $table->string('status', 32)->default('QUEUED');
            $table->longText('body_encrypted')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('gateway_event_id', 128)->nullable();
            $table->char('content_digest', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['inbox_id', 'provider_message_id'], 'comm_messages_inbox_provider_uq');
            $table->unique(['inbox_id', 'gateway_event_id'], 'comm_messages_inbox_event_uq');
            $table->index(['office_id', 'conversation_id', 'occurred_at'], 'comm_messages_timeline_idx');
            $table->index(['office_id', 'status', 'created_at'], 'comm_messages_status_idx');
        });

        Schema::create('communication_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('communication_messages')->cascadeOnDelete();
            $table->string('object_id', 26)->unique();
            $table->text('original_name_encrypted')->nullable();
            $table->string('mime_type', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('disposition', 16)->default('attachment');
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'message_id'], 'comm_attachments_message_idx');
            $table->index(['office_id', 'sha256'], 'comm_attachments_digest_idx');
        });

        Schema::create('communication_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color', 16)->default('neutral');
            $table->timestamps();

            $table->unique(['office_id', 'name'], 'comm_labels_office_name_uq');
        });

        Schema::create('communication_conversation_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('label_id')->constrained('communication_labels')->cascadeOnDelete();
            $table->foreignId('assigned_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['conversation_id', 'label_id'], 'comm_conversation_labels_uq');
        });

        Schema::create('communication_canned_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('shortcut', 80);
            $table->longText('body_encrypted');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'shortcut'], 'comm_canned_office_shortcut_uq');
        });

        Schema::create('communication_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->nullable()->constrained('communication_inboxes')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('communication_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('communication_messages')->cascadeOnDelete();
            $table->foreignId('actor_membership_id')->nullable()->constrained('office_user')->nullOnDelete();
            $table->string('type', 80);
            $table->string('gateway_event_id', 128)->nullable()->unique();
            $table->char('payload_digest', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'id'], 'comm_events_office_cursor_idx');
            $table->index(['office_id', 'inbox_id', 'id'], 'comm_events_inbox_cursor_idx');
        });

        Schema::create('communication_outbox_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_id')->nullable()->constrained('communication_inboxes')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('communication_messages')->cascadeOnDelete();
            $table->string('command_id', 128)->unique();
            $table->string('session_id', 128);
            $table->string('type', 40);
            $table->longText('payload_encrypted');
            $table->char('payload_digest', 64);
            $table->string('status', 32)->default('PENDING');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'comm_outbox_dispatch_idx');
            $table->index(['office_id', 'inbox_id', 'status'], 'comm_outbox_inbox_idx');
        });

        Schema::create('communication_automation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 40);
            $table->string('submodule_key', 40);
            $table->foreignId('inbox_id')->nullable()->constrained('communication_inboxes')->nullOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedTinyInteger('send_day')->default(1);
            $table->time('send_time')->default('09:00');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('recipient_mode', 24)->default('PRIMARY');
            $table->string('template_key', 80);
            $table->string('template_version', 40)->default('1');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['office_id', 'module_key', 'submodule_key'], 'comm_automation_policy_scope_uq');
            $table->index(['is_enabled', 'send_day', 'send_time'], 'comm_automation_policy_due_idx');
        });

        Schema::create('communication_preference_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preference_id')->constrained('client_communication_preferences')->cascadeOnDelete();
            $table->foreignId('identity_id')->constrained('communication_identities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['preference_id', 'identity_id'], 'comm_preference_recipients_uq');
            $table->index(['office_id', 'identity_id'], 'comm_preference_recipients_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_preference_recipients');
        Schema::dropIfExists('communication_automation_policies');
        Schema::dropIfExists('communication_outbox_entries');
        Schema::dropIfExists('communication_events');
        Schema::dropIfExists('communication_canned_responses');
        Schema::dropIfExists('communication_conversation_labels');
        Schema::dropIfExists('communication_labels');
        Schema::dropIfExists('communication_attachments');
        Schema::dropIfExists('communication_messages');
        Schema::dropIfExists('communication_conversation_clients');
        DB::statement('DROP INDEX IF EXISTS comm_conversations_one_active_uq');
        Schema::dropIfExists('communication_conversations');
        DB::statement('DROP INDEX IF EXISTS comm_identity_links_contact_uq');
        DB::statement('DROP INDEX IF EXISTS comm_identity_links_client_uq');
        Schema::dropIfExists('communication_identity_links');
        Schema::dropIfExists('communication_identities');
        Schema::dropIfExists('communication_contacts');
        Schema::dropIfExists('communication_inbox_members');
        DB::statement('DROP INDEX IF EXISTS comm_inboxes_one_default_per_office');
        Schema::dropIfExists('communication_inboxes');
    }
};
