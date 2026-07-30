<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_conversation_bulk_operation_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('bulk_operation_id');
            $table->unsignedInteger('item_index');
            $table->bigInteger('conversation_id');
            $table->bigInteger('live_conversation_id')->nullable();
            $table->bigInteger('resolved_conversation_id')->nullable();
            $table->bigInteger('inbox_id');
            $table->bigInteger('live_inbox_id')->nullable();
            $table->integer('lock_version')->nullable();
            $table->bigInteger('through_message_id')->nullable();
            $table->unsignedBigInteger('read_state_version')->nullable();
            $table->string('status', 32)->default('QUEUED');
            $table->string('result_code', 60)->nullable();
            $table->string('result_message', 500)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'bulk_operation_id', 'item_index'],
                'communication_bulk_items_tenant_op_index_uidx',
            );
            $table->unique(
                ['tenant_id', 'bulk_operation_id', 'conversation_id'],
                'communication_bulk_items_tenant_op_conversation_uidx',
            );
            $table->index(
                ['tenant_id', 'bulk_operation_id', 'status'],
                'communication_bulk_items_tenant_op_status_idx',
            );
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bulk_operation_id')
                ->references('id')
                ->on('communication_conversation_bulk_operations')
                ->cascadeOnDelete();
            $table->foreign('live_conversation_id')
                ->references('id')
                ->on('communication_conversations')
                ->nullOnDelete();
            $table->foreign('resolved_conversation_id')
                ->references('id')
                ->on('communication_conversations')
                ->nullOnDelete();
            $table->foreign('live_inbox_id')
                ->references('id')
                ->on('communication_inboxes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_conversation_bulk_operation_items');
    }
};
