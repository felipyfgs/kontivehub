<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove colunas denormalizadas legadas de read-state em
 * communication_conversations (schema drift de migration removida).
 *
 * A fonte de verdade é o ledger:
 * - communication_conversation_unread_messages
 * - communication_conversation_read_states
 *
 * Manter unread_count/first_unread_message_id na linha colidia com o
 * withCount/addSelect da listagem (ORDER BY ambíguo no PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_conversations')) {
            return;
        }

        if ($this->indexExists('communication_conversations_tenant_unread_idx')) {
            DB::statement('DROP INDEX IF EXISTS communication_conversations_tenant_unread_idx');
        }

        Schema::table('communication_conversations', function (Blueprint $table) {
            $drop = [];
            foreach (['unread_count', 'first_unread_message_id', 'last_read_message_id', 'last_read_at'] as $column) {
                if (Schema::hasColumn('communication_conversations', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_conversations')) {
            return;
        }

        Schema::table('communication_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('communication_conversations', 'unread_count')) {
                $table->integer('unread_count')->default(0);
            }
            if (! Schema::hasColumn('communication_conversations', 'first_unread_message_id')) {
                $table->bigInteger('first_unread_message_id')->nullable();
            }
            if (! Schema::hasColumn('communication_conversations', 'last_read_message_id')) {
                $table->bigInteger('last_read_message_id')->nullable();
            }
            if (! Schema::hasColumn('communication_conversations', 'last_read_at')) {
                $table->timestampTz('last_read_at')->nullable();
            }
        });

        DB::statement(<<<'SQL'
            UPDATE communication_conversations AS conversation
            SET unread_count = unread.count,
                first_unread_message_id = unread.first_message_id
            FROM (
                SELECT tenant_id, conversation_id, COUNT(*)::integer AS count, MIN(message_id) AS first_message_id
                FROM communication_conversation_unread_messages
                GROUP BY tenant_id, conversation_id
            ) AS unread
            WHERE conversation.tenant_id = unread.tenant_id
              AND conversation.id = unread.conversation_id
        SQL);
        DB::statement(<<<'SQL'
            UPDATE communication_conversations AS conversation
            SET last_read_message_id = state.last_read_through_message_id,
                last_read_at = state.updated_at
            FROM communication_conversation_read_states AS state
            WHERE conversation.tenant_id = state.tenant_id
              AND conversation.id = state.conversation_id
        SQL);

        if (! $this->indexExists('communication_conversations_tenant_unread_idx')) {
            DB::statement(<<<'SQL'
                CREATE INDEX communication_conversations_tenant_unread_idx
                    ON communication_conversations (tenant_id, unread_count, last_message_at)
            SQL);
        }
    }

    private function indexExists(string $name): bool
    {
        $row = DB::selectOne(
            'select 1 as ok from pg_indexes where schemaname = current_schema() and indexname = ? limit 1',
            [$name],
        );

        return $row !== null;
    }
};
