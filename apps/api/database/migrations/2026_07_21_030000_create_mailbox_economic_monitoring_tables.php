<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 24)->default('ECONOMICO');
            $table->string('daily_time', 5)->default('00:30');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->unsignedSmallInteger('reconciliation_days')->default(30);
            $table->unsignedSmallInteger('auto_detail_limit')->default(0);
            $table->unsignedBigInteger('monthly_budget_micros')->nullable();
            $table->timestampTz('last_dispatched_at')->nullable();
            $table->timestampTz('next_due_at')->nullable();
            $table->timestamps();

            $table->unique('office_id', 'mailbox_monitoring_office_uq');
            $table->index(['enabled', 'next_due_at'], 'mailbox_monitoring_due_idx');
        });

        Schema::create('mailbox_client_sync_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('bootstrap_completed_at')->nullable();
            $table->date('last_event_observed_date')->nullable();
            $table->date('pending_event_date')->nullable();
            $table->date('last_reconciled_event_date')->nullable();
            $table->timestampTz('last_list_at')->nullable();
            $table->timestampTz('last_full_reconciliation_at')->nullable();
            $table->string('authorization_status', 32)->default('UNKNOWN');
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'client_id'], 'mailbox_sync_office_client_uq');
            $table->index(['office_id', 'pending_event_date'], 'mailbox_sync_pending_idx');
            $table->index(['office_id', 'last_full_reconciliation_at'], 'mailbox_sync_reconcile_idx');
        });

        Schema::table('serpro_eventos_runs', function (Blueprint $table) {
            $table->string('result_vault_object_id', 26)->nullable()->after('result_fingerprint');
            $table->string('result_payload_sha256', 64)->nullable()->after('result_vault_object_id');
            $table->timestampTz('remote_result_received_at')->nullable()->after('result_payload_sha256');
            $table->string('local_processing_status', 32)->default('NOT_RECEIVED')->after('remote_result_received_at');
            $table->timestampTz('local_processed_at')->nullable()->after('local_processing_status');
        });

        Schema::create('serpro_eventos_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serpro_eventos_run_id')->constrained('serpro_eventos_runs')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ni_fingerprint', 64);
            $table->string('classification', 32);
            $table->date('event_date')->nullable();
            $table->string('processing_status', 24)->default('PENDING');
            $table->foreignId('directed_run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->unique(['serpro_eventos_run_id', 'ni_fingerprint'], 'serpro_event_item_run_ni_uq');
            $table->index(['office_id', 'processing_status'], 'serpro_event_item_office_status_idx');
        });

        Schema::table('mailbox_contributor_states', function (Blueprint $table) {
            $table->unsignedTinyInteger('new_messages_indicator')->nullable();
            $table->timestampTz('new_messages_indicator_observed_at')->nullable();
            $table->foreignId('last_new_messages_indicator_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mailbox_contributor_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_new_messages_indicator_run_id');
            $table->dropColumn(['new_messages_indicator', 'new_messages_indicator_observed_at']);
        });
        Schema::dropIfExists('serpro_eventos_run_items');
        Schema::table('serpro_eventos_runs', function (Blueprint $table) {
            $table->dropColumn([
                'result_vault_object_id',
                'result_payload_sha256',
                'remote_result_received_at',
                'local_processing_status',
                'local_processed_at',
            ]);
        });
        Schema::dropIfExists('mailbox_client_sync_states');
        Schema::dropIfExists('mailbox_monitoring_settings');
    }
};
