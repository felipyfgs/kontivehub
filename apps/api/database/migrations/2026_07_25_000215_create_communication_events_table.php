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
        Schema::create('communication_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id')->nullable();
            $table->bigInteger('conversation_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->bigInteger('actor_membership_id')->nullable();
            $table->string('type', 80);
            $table->string('gateway_event_id', 128)->nullable()->unique();
            $table->char('payload_digest', 64)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'inbox_id', 'id']);
            $table->index(['tenant_id', 'id']);
            $table->foreign(['actor_membership_id'])->references(['id'])->on('tenant_memberships')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['message_id'])->references(['id'])->on('communication_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_events');
    }
};
