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
        Schema::create('communication_outbox_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('inbox_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->string('command_id', 128)->unique();
            $table->string('session_id', 128);
            $table->string('type', 40);
            $table->text('payload_encrypted');
            $table->char('payload_digest', 64);
            $table->string('status', 32)->default('PENDING');
            $table->integer('attempt_count')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestampsTz();
            $table->string('effect_key', 160)->nullable()->unique();

            $table->index(['status', 'available_at']);
            $table->index(['tenant_id', 'inbox_id', 'status']);
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['message_id'])->references(['id'])->on('communication_messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_outbox_entries');
    }
};
