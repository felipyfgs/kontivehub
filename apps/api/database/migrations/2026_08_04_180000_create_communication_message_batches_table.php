<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_message_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id');
            $table->bigInteger('conversation_id');
            $table->string('client_batch_id', 128);
            $table->char('request_digest', 64);
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedSmallInteger('item_count');
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'conversation_id', 'client_batch_id'],
                'communication_message_batches_idempotency_uidx',
            );
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inbox_id')->references('id')->on('communication_inboxes')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('communication_conversations')->cascadeOnDelete();
        });

        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->bigInteger('message_batch_id')->nullable();
            $table->unsignedSmallInteger('batch_position')->nullable();
            $table->unique(
                ['message_batch_id', 'batch_position'],
                'communication_messages_batch_position_uidx',
            );
            $table->foreign('message_batch_id')
                ->references('id')
                ->on('communication_message_batches')
                ->nullOnDelete();
        });

        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->bigInteger('message_batch_id')->nullable();
            $table->unique('message_batch_id', 'communication_outbox_message_batch_uidx');
            $table->foreign('message_batch_id')
                ->references('id')
                ->on('communication_message_batches')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communication_outbox_entries', function (Blueprint $table): void {
            $table->dropForeign(['message_batch_id']);
            $table->dropUnique('communication_outbox_message_batch_uidx');
            $table->dropColumn('message_batch_id');
        });
        Schema::table('communication_messages', function (Blueprint $table): void {
            $table->dropForeign(['message_batch_id']);
            $table->dropUnique('communication_messages_batch_position_uidx');
            $table->dropColumn(['message_batch_id', 'batch_position']);
        });
        Schema::dropIfExists('communication_message_batches');
    }
};
