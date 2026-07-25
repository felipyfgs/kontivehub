<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caixa Postal / DTE (tasks 10.5–10.8).
 *
 * - Estado de contribuinte com proveniência separada (DTE ≠ mensagens)
 * - Mensagens/anexos no cofre com retenção e classificação sensível
 * - Triagem interna NEW/IN_REVIEW/RESOLVED distinta de leitura oficial
 * - Alertas sanitizados e trilha de visualização/download
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_contributor_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // DTE — fonte própria (não infere mensagens)
            $table->string('dte_status', 20)->default('UNKNOWN');
            $table->string('dte_source', 40)->nullable(); // DTE_INDICATOR
            $table->timestampTz('dte_observed_at')->nullable();
            $table->foreignId('last_dte_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();

            // Mensagens Caixa Postal — fonte própria (não infere DTE)
            $table->string('messages_status', 20)->default('UNKNOWN');
            $table->string('messages_source', 40)->nullable(); // CAIXA_POSTAL
            $table->timestampTz('messages_observed_at')->nullable();
            $table->foreignId('last_list_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->unsignedInteger('official_unread_count')->nullable();
            $table->unsignedInteger('stored_message_count')->default(0);

            $table->json('metadata')->nullable(); // sanitizado
            $table->timestamps();

            $table->unique(['office_id', 'client_id'], 'mcs_office_client_uq');
            $table->index(['office_id', 'dte_status'], 'mcs_office_dte_idx');
            $table->index(['office_id', 'messages_status'], 'mcs_office_msg_idx');
        });

        Schema::create('mailbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            /** Identificador oficial (quando existir). */
            $table->string('external_id', 160);
            /** Hash canônico office+client+external_id (dedupe). */
            $table->string('message_hash', 64);
            $table->string('source', 40)->default('CAIXA_POSTAL');
            $table->string('sensitivity_class', 40)->default('FISCAL_RESTRICTED');

            $table->string('category_code', 80)->nullable();
            $table->string('category_label', 160)->nullable();
            $table->string('sender_code', 80)->nullable();
            $table->string('sender_label', 160)->nullable();
            /** Assunto curto para UI autenticada — NÃO copiar para inbox/log. */
            $table->string('subject_preview', 255)->nullable();

            $table->timestampTz('received_at_official')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('severity_hint', 20)->nullable();

            /**
             * Indicador oficial de leitura/ciência remota (fonte).
             * Nunca alterado só porque operador abriu detalhe interno.
             */
            $table->boolean('official_read_indicator')->nullable();
            $table->timestampTz('official_read_observed_at')->nullable();

            /** Triagem interna (NEW / IN_REVIEW / RESOLVED). */
            $table->string('triage_status', 20)->default('NEW');
            $table->foreignId('triage_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('triage_at')->nullable();
            $table->text('triage_note')->nullable();

            // Corpo no cofre
            $table->string('body_vault_object_id', 26)->nullable();
            $table->string('body_sha256', 64)->nullable();
            $table->string('body_content_type', 80)->nullable();
            $table->unsignedBigInteger('body_byte_size')->default(0);
            $table->boolean('has_body')->default(false);
            $table->unsignedSmallInteger('attachment_count')->default(0);

            $table->timestampTz('retention_until')->nullable();
            $table->foreignId('first_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('last_run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('evidence_artifact_id')->nullable()
                ->constrained('fiscal_evidence_artifacts')->nullOnDelete();

            $table->json('metadata')->nullable(); // sem corpo/anexo
            $table->timestamps();

            $table->unique(['office_id', 'message_hash'], 'mm_office_hash_uq');
            $table->unique(['office_id', 'client_id', 'external_id'], 'mm_office_client_ext_uq');
            $table->index(['office_id', 'client_id', 'triage_status'], 'mm_office_client_triage_idx');
            $table->index(['office_id', 'due_at'], 'mm_office_due_idx');
            $table->index(['office_id', 'received_at_official'], 'mm_office_recv_idx');
        });

        Schema::create('mailbox_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->string('external_id', 160)->nullable();
            $table->string('filename_sanitized', 255)->nullable();
            $table->string('content_type', 80)->default('application/octet-stream');
            $table->string('vault_object_id', 26);
            $table->string('content_sha256', 64);
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('sensitivity_class', 40)->default('FISCAL_RESTRICTED');
            $table->timestampTz('retention_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['office_id', 'mailbox_message_id', 'content_sha256'],
                'ma_office_msg_sha_uq'
            );
            $table->index(['office_id', 'mailbox_message_id'], 'ma_office_msg_idx');
        });

        Schema::create('mailbox_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->foreignId('mailbox_attachment_id')->nullable()
                ->constrained('mailbox_attachments')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            /** Metadados sanitizados — sem corpo/anexo/token. */
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'mailbox_message_id', 'created_at'], 'mae_office_msg_created_idx');
            $table->index(['office_id', 'user_id', 'created_at'], 'mae_office_user_created_idx');
        });

        Schema::create('mailbox_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_message_id')->constrained('mailbox_messages')->cascadeOnDelete();
            $table->string('severity', 20)->default('medium');
            /** Título/corpo sanitizados — remetente/categoria/prazo, sem conteúdo fiscal. */
            $table->string('title', 255);
            $table->string('body', 500);
            $table->string('deep_link', 255);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('dismissed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'mailbox_message_id'], 'malert_office_msg_uq');
            $table->index(['office_id', 'is_active', 'severity'], 'malert_office_active_sev_idx');
        });

        $this->seedCatalogOps();
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_alerts');
        Schema::dropIfExists('mailbox_access_events');
        Schema::dropIfExists('mailbox_attachments');
        Schema::dropIfExists('mailbox_messages');
        Schema::dropIfExists('mailbox_contributor_states');
    }

    private function seedCatalogOps(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }

        $now = now();
        $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);

        $ops = [
            ['INTEGRA_CAIXAPOSTAL', 'CAIXA_POSTAL', 'DETALHE', 'Detalhe mensagem Caixa Postal', false, 'CAIXA_POSTAL', 'CONSULTA', 900],
            ['INTEGRA_CAIXAPOSTAL', 'DTE', 'INDICADOR', 'Indicador DTE', false, 'CAIXA_POSTAL', 'CONSULTA', 3600],
        ];

        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $env) {
            foreach ($ops as [$solution, $service, $operation, $label, $mutating, $power, $billable, $cache]) {
                $exists = DB::table('serpro_service_catalog_entries')
                    ->where('catalog_version', $version)
                    ->where('environment', $env)
                    ->where('solution_code', $solution)
                    ->where('service_code', $service)
                    ->where('operation_code', $operation)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('serpro_service_catalog_entries')->insert([
                    'catalog_version' => $version,
                    'environment' => $env,
                    'solution_code' => $solution,
                    'service_code' => $service,
                    'operation_code' => $operation,
                    'label' => $label,
                    'is_mutating' => $mutating,
                    'is_enabled' => ! $mutating,
                    'required_proxy_power' => $power,
                    'billable_class' => $billable,
                    'cache_ttl_seconds' => $cache,
                    'rate_limit_per_minute' => 30,
                    'coverage' => 'KNOWN',
                    'metadata' => null,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
