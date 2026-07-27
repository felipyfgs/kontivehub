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
        Schema::create('communication_flow_consumptions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('run_id')->nullable();
            $table->bigInteger('conversation_id')->nullable();
            $table->string('event_key', 128);
            $table->char('event_digest', 64)->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'event_key']);
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['run_id'])->references(['id'])->on('communication_flow_runs')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_consumptions');
    }
};
