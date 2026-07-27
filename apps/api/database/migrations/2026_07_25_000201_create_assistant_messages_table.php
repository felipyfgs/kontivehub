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
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('conversation_id');
            $table->string('role', 20);
            $table->text('content')->nullable();
            $table->jsonb('tool_calls')->nullable();
            $table->jsonb('tool_results')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'conversation_id', 'id']);
            $table->foreign(['conversation_id'])->references(['id'])->on('assistant_conversations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
