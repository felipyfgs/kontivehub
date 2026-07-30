<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->timestampTz('quarantined_at')->nullable();
            $table->string('quarantine_reason', 80)->nullable();
            $table->string('quarantine_operation_id', 64)->nullable();

            $table->index(
                ['tenant_id', 'conversation_id', 'quarantined_at', 'occurred_at', 'id'],
                'communication_messages_visible_timeline_idx',
            );
            $table->index(
                ['tenant_id', 'inbox_id', 'quarantine_operation_id'],
                'communication_messages_quarantine_operation_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->dropIndex('communication_messages_visible_timeline_idx');
            $table->dropIndex('communication_messages_quarantine_operation_idx');
            $table->dropColumn([
                'quarantined_at',
                'quarantine_reason',
                'quarantine_operation_id',
            ]);
        });
    }
};
