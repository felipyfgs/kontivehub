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
        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('external_id', 160);
            $table->string('message_hash', 64);
            $table->string('source', 40)->default('CAIXA_POSTAL');
            $table->string('sensitivity_class', 40)->default('FISCAL_RESTRICTED');
            $table->string('category_code', 80)->nullable();
            $table->string('category_label', 160)->nullable();
            $table->string('sender_code', 80)->nullable();
            $table->string('sender_label', 160)->nullable();
            $table->string('subject_preview')->nullable();
            $table->timestampTz('received_at_official')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('severity_hint', 20)->nullable();
            $table->boolean('official_read_indicator')->nullable();
            $table->timestampTz('official_read_observed_at')->nullable();
            $table->string('triage_status', 20)->default('NEW');
            $table->bigInteger('triage_by')->nullable();
            $table->timestampTz('triage_at')->nullable();
            $table->text('triage_note')->nullable();
            $table->string('body_vault_object_id', 26)->nullable();
            $table->string('body_sha256', 64)->nullable();
            $table->string('body_content_type', 80)->nullable();
            $table->bigInteger('body_byte_size')->default(0);
            $table->boolean('has_body')->default(false);
            $table->smallInteger('attachment_count')->default(0);
            $table->timestampTz('retention_until')->nullable();
            $table->bigInteger('first_run_id')->nullable();
            $table->bigInteger('last_run_id')->nullable();
            $table->bigInteger('evidence_artifact_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'client_id', 'external_id']);
            $table->index(['tenant_id', 'client_id', 'triage_status']);
            $table->index(['tenant_id', 'due_at']);
            $table->unique(['tenant_id', 'message_hash']);
            $table->index(['tenant_id', 'received_at_official']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['evidence_artifact_id'])->references(['id'])->on('fiscal_evidence_artifacts')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['first_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['last_run_id'])->references(['id'])->on('fiscal_monitoring_runs')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['triage_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mailbox_messages');
    }
};
