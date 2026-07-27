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
        Schema::create('communication_flow_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('flow_id');
            $table->bigInteger('flow_version_id');
            $table->bigInteger('binding_id')->nullable();
            $table->bigInteger('conversation_id')->nullable()->index();
            $table->string('status', 32)->default('pending');
            $table->string('current_node_id', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->text('context_encrypted')->nullable();
            $table->timestampTz('waiting_until')->nullable();
            $table->string('waiting_effect_key', 160)->nullable();
            $table->bigInteger('waiting_outbox_entry_id')->nullable();

            $table->index(['tenant_id', 'status']);
            $table->foreign(['binding_id'])->references(['id'])->on('communication_flow_inbox_bindings')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['flow_id'])->references(['id'])->on('communication_flows')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['flow_version_id'])->references(['id'])->on('communication_flow_versions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['waiting_outbox_entry_id'])->references(['id'])->on('communication_outbox_entries')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_flow_runs');
    }
};
