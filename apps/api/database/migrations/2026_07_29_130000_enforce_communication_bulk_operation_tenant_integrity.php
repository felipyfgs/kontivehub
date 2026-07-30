<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE tenant_memberships
            ADD CONSTRAINT tenant_memberships_tenant_id_user_uidx
            UNIQUE (tenant_id, id, user_id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_bulk_operations
            ADD CONSTRAINT communication_bulk_ops_tenant_id_uidx
            UNIQUE (tenant_id, id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversations
            ADD CONSTRAINT communication_conversations_tenant_id_uidx
            UNIQUE (tenant_id, id)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_inboxes
            ADD CONSTRAINT communication_inboxes_tenant_id_uidx
            UNIQUE (tenant_id, id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_bulk_operations
            DROP CONSTRAINT communication_conversation_bulk_operations_requested_by_members,
            ADD CONSTRAINT communication_bulk_ops_tenant_membership_user_fk
            FOREIGN KEY (tenant_id, requested_by_membership_id, requested_by_user_id)
            REFERENCES tenant_memberships (tenant_id, id, user_id)
            ON DELETE SET NULL (requested_by_membership_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_bulk_operation_items
            DROP CONSTRAINT communication_conversation_bulk_operation_items_bulk_operation_,
            DROP CONSTRAINT communication_conversation_bulk_operation_items_live_conversati,
            DROP CONSTRAINT communication_conversation_bulk_operation_items_resolved_conver,
            DROP CONSTRAINT communication_conversation_bulk_operation_items_live_inbox_id_f,
            ADD CONSTRAINT communication_bulk_items_tenant_operation_fk
            FOREIGN KEY (tenant_id, bulk_operation_id)
            REFERENCES communication_conversation_bulk_operations (tenant_id, id)
            ON DELETE CASCADE,
            ADD CONSTRAINT communication_bulk_items_tenant_conversation_fk
            FOREIGN KEY (tenant_id, live_conversation_id)
            REFERENCES communication_conversations (tenant_id, id)
            ON DELETE SET NULL (live_conversation_id),
            ADD CONSTRAINT communication_bulk_items_tenant_resolved_fk
            FOREIGN KEY (tenant_id, resolved_conversation_id)
            REFERENCES communication_conversations (tenant_id, id)
            ON DELETE SET NULL (resolved_conversation_id),
            ADD CONSTRAINT communication_bulk_items_tenant_inbox_fk
            FOREIGN KEY (tenant_id, live_inbox_id)
            REFERENCES communication_inboxes (tenant_id, id)
            ON DELETE SET NULL (live_inbox_id),
            ADD CONSTRAINT communication_bulk_items_conversation_snapshot_check
            CHECK (live_conversation_id IS NULL OR live_conversation_id = conversation_id),
            ADD CONSTRAINT communication_bulk_items_inbox_snapshot_check
            CHECK (live_inbox_id IS NULL OR live_inbox_id = inbox_id)
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX communication_bulk_items_operation_status_idx
            ON communication_conversation_bulk_operation_items
            (bulk_operation_id, status, item_index)
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX communication_bulk_items_live_conversation_idx
            ON communication_conversation_bulk_operation_items
            (tenant_id, live_conversation_id)
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX communication_bulk_items_live_inbox_idx
            ON communication_conversation_bulk_operation_items
            (tenant_id, live_inbox_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX communication_bulk_items_live_inbox_idx');
        DB::statement('DROP INDEX communication_bulk_items_live_conversation_idx');
        DB::statement('DROP INDEX communication_bulk_items_operation_status_idx');
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_bulk_operation_items
            DROP CONSTRAINT communication_bulk_items_tenant_operation_fk,
            DROP CONSTRAINT communication_bulk_items_tenant_conversation_fk,
            DROP CONSTRAINT communication_bulk_items_tenant_resolved_fk,
            DROP CONSTRAINT communication_bulk_items_tenant_inbox_fk,
            DROP CONSTRAINT communication_bulk_items_conversation_snapshot_check,
            DROP CONSTRAINT communication_bulk_items_inbox_snapshot_check,
            ADD CONSTRAINT communication_conversation_bulk_operation_items_bulk_operation_
            FOREIGN KEY (bulk_operation_id)
            REFERENCES communication_conversation_bulk_operations (id)
            ON DELETE CASCADE,
            ADD CONSTRAINT communication_conversation_bulk_operation_items_live_conversati
            FOREIGN KEY (live_conversation_id)
            REFERENCES communication_conversations (id)
            ON DELETE SET NULL,
            ADD CONSTRAINT communication_conversation_bulk_operation_items_resolved_conver
            FOREIGN KEY (resolved_conversation_id)
            REFERENCES communication_conversations (id)
            ON DELETE SET NULL,
            ADD CONSTRAINT communication_conversation_bulk_operation_items_live_inbox_id_f
            FOREIGN KEY (live_inbox_id)
            REFERENCES communication_inboxes (id)
            ON DELETE SET NULL
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE communication_conversation_bulk_operations
            DROP CONSTRAINT communication_bulk_ops_tenant_membership_user_fk,
            ADD CONSTRAINT communication_conversation_bulk_operations_requested_by_members
            FOREIGN KEY (requested_by_membership_id)
            REFERENCES tenant_memberships (id)
            ON DELETE SET NULL
        SQL);

        DB::statement('ALTER TABLE communication_inboxes DROP CONSTRAINT communication_inboxes_tenant_id_uidx');
        DB::statement('ALTER TABLE communication_conversations DROP CONSTRAINT communication_conversations_tenant_id_uidx');
        DB::statement('ALTER TABLE communication_conversation_bulk_operations DROP CONSTRAINT communication_bulk_ops_tenant_id_uidx');
        DB::statement('ALTER TABLE tenant_memberships DROP CONSTRAINT tenant_memberships_tenant_id_user_uidx');
    }
};
