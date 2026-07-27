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
        Schema::create('mailbox_client_sync_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->timestampTz('bootstrap_completed_at')->nullable();
            $table->date('last_event_observed_date')->nullable();
            $table->date('pending_event_date')->nullable();
            $table->date('last_reconciled_event_date')->nullable();
            $table->timestampTz('last_list_at')->nullable();
            $table->timestampTz('last_full_reconciliation_at')->nullable();
            $table->string('authorization_status', 32)->default('UNKNOWN');
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id']);
            $table->index(['tenant_id', 'pending_event_date']);
            $table->index(['tenant_id', 'last_full_reconciliation_at'], 'mailbox_client_sync_states_tenant_id_last_full_rec_86d8657738');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_client_sync_states');
    }
};
