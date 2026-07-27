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
        Schema::create('client_communication_dispatches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('preference_id')->nullable();
            $table->bigInteger('projection_id')->nullable();
            $table->bigInteger('pgdasd_artifact_id')->nullable();
            $table->string('module_key', 40)->default('simples_mei');
            $table->string('submodule_key', 40)->default('pgdasd');
            $table->string('period_key', 20)->nullable();
            $table->string('channel', 20);
            $table->string('status', 20)->default('QUEUED');
            $table->string('recipient_masked');
            $table->string('recipient_hash', 64);
            $table->string('idempotency_key', 64);
            $table->string('template_key', 80)->nullable();
            $table->string('template_version', 40)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_external_id')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->bigInteger('inbox_id')->nullable();
            $table->bigInteger('identity_id')->nullable();
            $table->bigInteger('conversation_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->string('artifact_type', 120)->nullable();
            $table->bigInteger('artifact_id')->nullable();
            $table->char('artifact_digest', 64)->nullable();
            $table->string('execution_mode', 24)->default('TEMPLATE_ONLY');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('skipped_at')->nullable();

            $table->index(['artifact_type', 'artifact_id']);
            $table->index(['tenant_id', 'client_id', 'channel', 'created_at'], 'client_communication_dispatches_tenant_id_client_i_3e0c9d8534');
            $table->index(['tenant_id', 'client_id', 'module_key', 'submodule_key'], 'client_communication_dispatches_tenant_id_client_i_84ea415c36');
            $table->unique(['tenant_id', 'idempotency_key'], 'client_communication_dispatches_tenant_id_idempote_315ff15909');
            $table->index(['tenant_id', 'identity_id', 'period_key'], 'client_communication_dispatches_tenant_id_identity_a52db3c539');
            $table->index(['tenant_id', 'inbox_id', 'status', 'scheduled_at'], 'client_communication_dispatches_tenant_id_inbox_id_d2c293ce69');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['conversation_id'])->references(['id'])->on('communication_conversations')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['identity_id'])->references(['id'])->on('communication_identities')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['inbox_id'])->references(['id'])->on('communication_inboxes')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['pgdasd_artifact_id'])->references(['id'])->on('pgdasd_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['preference_id'])->references(['id'])->on('client_communication_preferences')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['projection_id'])->references(['id'])->on('tax_obligation_projections')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_communication_dispatches');
    }
};
