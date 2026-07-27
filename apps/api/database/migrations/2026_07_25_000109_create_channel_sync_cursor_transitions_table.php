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
        Schema::create('channel_sync_cursor_transitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('channel_sync_cursor_id');
            $table->string('channel', 40);
            $table->string('event', 60);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->bigInteger('from_last_nsu')->nullable();
            $table->bigInteger('to_last_nsu')->nullable();
            $table->string('last_cstat', 10)->nullable();
            $table->string('correlation_id', 64)->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'channel_sync_cursor_id', 'occurred_at'], 'channel_sync_cursor_transitions_tenant_id_channel__6078ad2bbb');
            $table->index(['tenant_id', 'event']);
            $table->foreign(['channel_sync_cursor_id'])->references(['id'])->on('channel_sync_cursors')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_sync_cursor_transitions');
    }
};
