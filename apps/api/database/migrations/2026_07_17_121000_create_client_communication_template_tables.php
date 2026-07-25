<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_communication_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 40)->default('simples_mei');
            $table->string('submodule_key', 40)->default('pgdasd');
            $table->boolean('automatic_requested')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'module_key', 'submodule_key'],
                'ccp_office_client_module_submodule_uq'
            );
            $table->index(
                ['office_id', 'module_key', 'submodule_key', 'automatic_requested'],
                'ccp_office_module_submodule_auto_idx'
            );
        });

        Schema::create('client_communication_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preference_id')
                ->nullable()
                ->constrained('client_communication_preferences')
                ->nullOnDelete();
            $table->foreignId('projection_id')
                ->nullable()
                ->constrained('tax_obligation_projections')
                ->nullOnDelete();
            $table->foreignId('pgdasd_artifact_id')
                ->nullable()
                ->constrained('pgdasd_artifacts')
                ->nullOnDelete();
            $table->string('module_key', 40)->default('simples_mei');
            $table->string('submodule_key', 40)->default('pgdasd');
            $table->string('period_key', 20)->nullable();
            $table->string('channel', 20);
            $table->string('status', 20)->default('QUEUED');
            $table->string('recipient_masked', 255);
            $table->string('recipient_hash', 64);
            $table->string('idempotency_key', 64);
            $table->string('template_key', 80)->nullable();
            $table->string('template_version', 40)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_external_id', 255)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'idempotency_key'],
                'ccd_office_idempotency_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'module_key', 'submodule_key'],
                'ccd_office_client_module_idx'
            );
            $table->index(
                ['office_id', 'client_id', 'channel', 'created_at'],
                'ccd_office_client_channel_created_idx'
            );
        });

        Schema::create('client_communication_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dispatch_id')
                ->constrained('client_communication_dispatches')
                ->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->nullable();
            $table->string('source', 40)->default('INTERNAL');
            $table->string('provider_event_id', 255)->nullable();
            $table->string('payload_digest', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['office_id', 'dispatch_id', 'occurred_at'],
                'cce_office_dispatch_occurred_idx'
            );
            $table->unique(
                ['office_id', 'provider_event_id'],
                'cce_office_provider_event_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_communication_events');
        Schema::dropIfExists('client_communication_dispatches');
        Schema::dropIfExists('client_communication_preferences');
    }
};
