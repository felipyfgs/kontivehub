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
        Schema::create('mailbox_contributor_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('dte_status', 20)->default('UNKNOWN');
            $table->string('dte_source', 40)->nullable();
            $table->timestampTz('dte_observed_at')->nullable();
            $table->bigInteger('last_dte_run_id')->nullable();
            $table->string('messages_status', 20)->default('UNKNOWN');
            $table->string('messages_source', 40)->nullable();
            $table->timestampTz('messages_observed_at')->nullable();
            $table->bigInteger('last_list_run_id')->nullable();
            $table->integer('official_unread_count')->nullable();
            $table->integer('stored_message_count')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->smallInteger('new_messages_indicator')->nullable();
            $table->timestampTz('new_messages_indicator_observed_at')->nullable();
            $table->bigInteger('last_new_messages_indicator_run_id')->nullable();

            $table->unique(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'dte_status']);
            $table->index(['tenant_id', 'messages_status']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['last_dte_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_list_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_new_messages_indicator_run_id'], 'mailbox_contributor_states_last_new_messages_indic_23b5a7ee97')->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_contributor_states');
    }
};
