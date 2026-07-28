<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_contacts', function (Blueprint $table) {
            $table->bigInteger('merged_into_contact_id')->nullable();
            $table->index(
                ['tenant_id', 'merged_into_contact_id'],
                'communication_contacts_merged_idx',
            );
            $table->unique(
                ['tenant_id', 'id'],
                'communication_contacts_merged_target_unique',
            );
            $table->foreign(
                ['tenant_id', 'merged_into_contact_id'],
                'communication_contact_merged_fk',
            )
                ->references(['tenant_id', 'id'])
                ->on('communication_contacts')
                ->onDelete('no action');
        });

        Schema::table('communication_identities', function (Blueprint $table) {
            $table->bigInteger('canonical_identity_id')->nullable();
            $table->index(
                ['tenant_id', 'canonical_identity_id'],
                'communication_identities_canonical_idx',
            );
            $table->unique(
                ['tenant_id', 'channel', 'id'],
                'communication_identities_canonical_target_unique',
            );
            $table->foreign(
                ['tenant_id', 'channel', 'canonical_identity_id'],
                'communication_identity_canonical_fk',
            )
                ->references(['tenant_id', 'channel', 'id'])
                ->on('communication_identities')
                ->onDelete('no action');
        });

        Schema::table('communication_conversations', function (Blueprint $table) {
            $table->bigInteger('merged_into_conversation_id')->nullable();
            $table->index(
                ['tenant_id', 'inbox_id', 'merged_into_conversation_id'],
                'communication_conversations_merged_idx',
            );
            $table->unique(
                ['tenant_id', 'inbox_id', 'id'],
                'communication_conversations_merged_target_unique',
            );
            $table->foreign(
                ['tenant_id', 'inbox_id', 'merged_into_conversation_id'],
                'communication_conversation_merged_fk',
            )
                ->references(['tenant_id', 'inbox_id', 'id'])
                ->on('communication_conversations')
                ->onDelete('no action');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE communication_contacts
            ADD CONSTRAINT communication_contact_merged_not_self
            CHECK (merged_into_contact_id IS NULL OR merged_into_contact_id <> id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_contacts
            ADD CONSTRAINT communication_contact_merged_inactive
            CHECK (merged_into_contact_id IS NULL OR is_active = false)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_identities
            ADD CONSTRAINT communication_identity_canonical_not_self
            CHECK (canonical_identity_id IS NULL OR canonical_identity_id <> id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversations
            ADD CONSTRAINT communication_conversation_merged_not_self
            CHECK (merged_into_conversation_id IS NULL OR merged_into_conversation_id <> id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversations
            ADD CONSTRAINT communication_conversation_merged_resolved
            CHECK (merged_into_conversation_id IS NULL OR status = 'RESOLVED')
        SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE communication_contacts DROP CONSTRAINT communication_contact_merged_inactive',
        );
        DB::statement(
            'ALTER TABLE communication_contacts DROP CONSTRAINT communication_contact_merged_not_self',
        );
        DB::statement(
            'ALTER TABLE communication_conversations DROP CONSTRAINT communication_conversation_merged_resolved',
        );
        DB::statement(
            'ALTER TABLE communication_conversations DROP CONSTRAINT communication_conversation_merged_not_self',
        );
        DB::statement(
            'ALTER TABLE communication_identities DROP CONSTRAINT communication_identity_canonical_not_self',
        );

        Schema::table('communication_conversations', function (Blueprint $table) {
            $table->dropForeign('communication_conversation_merged_fk');
            $table->dropIndex('communication_conversations_merged_idx');
            $table->dropUnique('communication_conversations_merged_target_unique');
            $table->dropColumn('merged_into_conversation_id');
        });
        Schema::table('communication_identities', function (Blueprint $table) {
            $table->dropForeign('communication_identity_canonical_fk');
            $table->dropIndex('communication_identities_canonical_idx');
            $table->dropUnique('communication_identities_canonical_target_unique');
            $table->dropColumn('canonical_identity_id');
        });
        Schema::table('communication_contacts', function (Blueprint $table) {
            $table->dropForeign('communication_contact_merged_fk');
            $table->dropIndex('communication_contacts_merged_idx');
            $table->dropUnique('communication_contacts_merged_target_unique');
            $table->dropColumn('merged_into_contact_id');
        });
    }
};
