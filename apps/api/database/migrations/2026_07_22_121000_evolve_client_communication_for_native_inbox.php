<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_communication_preferences', function (Blueprint $table): void {
            $table->string('recipient_mode', 24)->default('PRIMARY')->after('whatsapp_enabled');
        });

        Schema::table('client_communication_dispatches', function (Blueprint $table): void {
            $table->foreignId('inbox_id')->nullable()->after('preference_id')->constrained('communication_inboxes')->nullOnDelete();
            $table->foreignId('identity_id')->nullable()->after('inbox_id')->constrained('communication_identities')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('identity_id')->constrained('communication_conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->after('conversation_id')->constrained('communication_messages')->nullOnDelete();
            $table->string('artifact_type', 120)->nullable()->after('pgdasd_artifact_id');
            $table->unsignedBigInteger('artifact_id')->nullable()->after('artifact_type');
            $table->char('artifact_digest', 64)->nullable()->after('artifact_id');
            $table->string('execution_mode', 24)->default('TEMPLATE_ONLY')->after('channel');
            $table->timestampTz('scheduled_at')->nullable()->after('queued_at');
            $table->timestampTz('accepted_at')->nullable()->after('scheduled_at');
            $table->timestampTz('skipped_at')->nullable()->after('failed_at');

            $table->index(['office_id', 'inbox_id', 'status', 'scheduled_at'], 'ccd_office_inbox_schedule_idx');
            $table->index(['office_id', 'identity_id', 'period_key'], 'ccd_office_identity_period_idx');
            $table->index(['artifact_type', 'artifact_id'], 'ccd_artifact_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_communication_dispatches', function (Blueprint $table): void {
            $table->dropIndex('ccd_artifact_reference_idx');
            $table->dropIndex('ccd_office_identity_period_idx');
            $table->dropIndex('ccd_office_inbox_schedule_idx');
            $table->dropConstrainedForeignId('message_id');
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('identity_id');
            $table->dropConstrainedForeignId('inbox_id');
            $table->dropColumn([
                'artifact_type',
                'artifact_id',
                'artifact_digest',
                'execution_mode',
                'scheduled_at',
                'accepted_at',
                'skipped_at',
            ]);
        });

        Schema::table('client_communication_preferences', function (Blueprint $table): void {
            $table->dropColumn('recipient_mode');
        });
    }
};
