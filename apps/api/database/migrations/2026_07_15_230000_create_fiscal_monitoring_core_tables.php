<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Núcleo de monitoramento fiscal (tasks 7.2–7.3).
 *
 * - fiscal_categories: catálogo tipado (global — códigos estáveis para módulos filhos)
 * - Demais tabelas: plano de dados com office_id obrigatório
 * - Unique constraints para idempotência por tenant+contribuinte+sistema+serviço+competência+evento
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->string('module_key', 40)->nullable(); // FeatureFlags module (simples_mei, sitfis, …)
            $table->string('default_coverage', 30)->default('UNKNOWN');
            $table->string('default_mutability', 20)->default('READ_ONLY');
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'is_active']);
        });

        Schema::create('office_fiscal_category_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_category_id')->constrained('fiscal_categories')->cascadeOnDelete();
            $table->string('status', 32)->default('ACTIVE');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'fiscal_category_id'],
                'ofcl_office_client_category_uq'
            );
            $table->index(['office_id', 'status', 'fiscal_category_id'], 'ofcl_office_status_cat_idx');
            $table->index(['office_id', 'client_id'], 'ofcl_office_client_idx');
        });

        Schema::create('fiscal_competences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_category_id')->nullable()->constrained('fiscal_categories')->nullOnDelete();
            $table->string('period_key', 20); // YYYY | YYYY-MM | YYYY-QN
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'fiscal_category_id', 'period_key'],
                'fiscal_comp_office_client_cat_period_uq'
            );
            $table->index(['office_id', 'period_year', 'period_month'], 'fiscal_comp_period_idx');
            $table->index(['office_id', 'situation'], 'fiscal_comp_situation_idx');
        });

        Schema::create('fiscal_monitoring_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_category_id')->nullable()->constrained('fiscal_categories')->nullOnDelete();
            $table->foreignId('category_link_id')->nullable()
                ->constrained('office_fiscal_category_links')->nullOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80)->default('MONITOR');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('interval_minutes')->default(60);
            $table->unsignedTinyInteger('preferred_minute')->default(0); // 0–59 espalhamento
            $table->timestampTz('next_run_at')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_result', 30)->nullable();
            $table->string('last_skip_reason', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'system_code', 'service_code', 'operation_code'],
                'fms_office_client_sys_svc_op_uq'
            );
            $table->index(['is_enabled', 'next_run_at'], 'fms_enabled_next_idx');
            $table->index(['office_id', 'is_enabled', 'next_run_at'], 'fms_office_enabled_next_idx');
        });

        Schema::create('fiscal_last_update_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80)->nullable();
            $table->string('event_type', 80);
            $table->string('event_external_id', 160)->nullable();
            /** Hash canônico para deduplicação (tenant + origem + id/payload). */
            $table->string('event_hash', 64);
            $table->string('payload_digest', 64)->nullable();
            $table->string('status', 32)->default('RECEIVED');
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->foreignId('directed_run_id')->nullable(); // FK adiada após fiscal_monitoring_runs
            $table->json('metadata')->nullable(); // sem payload fiscal bruto sensível
            $table->timestamps();

            $table->unique(['office_id', 'event_hash'], 'flue_office_event_hash_uq');
            $table->index(['office_id', 'system_code', 'status'], 'flue_office_sys_status_idx');
            $table->index(['office_id', 'client_id', 'received_at'], 'flue_office_client_recv_idx');
        });

        Schema::create('fiscal_monitoring_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_category_id')->nullable()->constrained('fiscal_categories')->nullOnDelete();
            $table->foreignId('competence_id')->nullable()->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('fiscal_monitoring_schedules')->nullOnDelete();
            $table->foreignId('last_update_event_id')->nullable()
                ->constrained('fiscal_last_update_events')->nullOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('trigger', 30);
            $table->string('idempotency_key', 160);
            $table->string('status', 32)->default('QUEUED');
            $table->string('result', 30)->nullable();
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('UNKNOWN');
            $table->string('mutability', 20)->default('READ_ONLY');
            $table->unsignedInteger('attempt')->default(1);
            $table->foreignId('parent_run_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('progress_cursor', 120)->nullable();
            $table->json('progress')->nullable();
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('pages_processed')->default(0);
            $table->string('skip_reason', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable(); // sanitizado
            $table->string('lease_owner', 64)->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('requeued_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'idempotency_key'], 'fmr_office_idempotency_uq');
            $table->index(['office_id', 'status', 'created_at'], 'fmr_office_status_created_idx');
            $table->index(['office_id', 'client_id', 'system_code', 'service_code'], 'fmr_office_client_sys_idx');
            $table->index(['office_id', 'competence_id'], 'fmr_office_competence_idx');
            $table->index(['status', 'locked_at'], 'fmr_status_locked_idx');
        });

        Schema::table('fiscal_monitoring_runs', function (Blueprint $table) {
            $table->foreign('parent_run_id')
                ->references('id')
                ->on('fiscal_monitoring_runs')
                ->nullOnDelete();
        });

        Schema::table('fiscal_last_update_events', function (Blueprint $table) {
            $table->foreign('directed_run_id')
                ->references('id')
                ->on('fiscal_monitoring_runs')
                ->nullOnDelete();
        });

        Schema::create('fiscal_evidence_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('fiscal_monitoring_runs')->cascadeOnDelete();
            $table->string('vault_object_id', 26);
            $table->string('content_sha256', 64);
            $table->string('content_type', 80)->default('application/json');
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('source', 80); // adapter / system
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('observed_at');
            $table->timestampTz('retention_until')->nullable();
            $table->boolean('is_immutable')->default(true);
            $table->json('metadata')->nullable(); // sem paths internos
            // Imutável: só created_at
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['office_id', 'content_sha256', 'run_id'], 'fea_office_sha_run_uq');
            $table->index(['office_id', 'run_id'], 'fea_office_run_idx');
            $table->index(['office_id', 'retention_until'], 'fea_office_retention_idx');
        });

        Schema::create('fiscal_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('fiscal_monitoring_runs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->nullable()->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('evidence_artifact_id')->nullable()
                ->constrained('fiscal_evidence_artifacts')->nullOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80)->nullable();
            $table->string('situation', 30);
            $table->string('coverage', 30);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->json('normalized')->nullable(); // projeção normalizada (não inventar UP_TO_DATE)
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'client_id', 'system_code', 'service_code', 'is_current'], 'fs_current_lookup_idx');
            $table->index(['office_id', 'run_id'], 'fs_office_run_idx');
            $table->index(['office_id', 'competence_id', 'is_current'], 'fs_office_comp_current_idx');
        });

        Schema::create('fiscal_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('fiscal_snapshots')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('fiscal_monitoring_runs')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('severity', 20)->default('INFO');
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('situation', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'is_active', 'severity'], 'ff_office_client_active_idx');
            $table->index(['office_id', 'snapshot_id'], 'ff_office_snapshot_idx');
            $table->unique(
                ['office_id', 'snapshot_id', 'code'],
                'ff_office_snapshot_code_uq'
            );
        });

        Schema::create('fiscal_pending_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('fiscal_snapshots')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('fiscal_category_id')->nullable()->constrained('fiscal_categories')->nullOnDelete();
            $table->foreignId('competence_id')->nullable()->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('finding_id')->nullable()->constrained('fiscal_findings')->nullOnDelete();
            $table->string('code', 80);
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('severity', 20)->default('MEDIUM');
            $table->string('status', 32)->default('OPEN');
            $table->string('situation', 30)->default('PENDING');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            /** Chave lógica estável (tenant+contribuinte+código). */
            $table->string('logical_key', 160);
            /**
             * Deduplicação de abertas: igual a logical_key enquanto OPEN; null quando encerrada.
             * Permite histórico de RESOLVED/DISMISSED sem colidir com nova abertura.
             */
            $table->string('open_dedupe_key', 160)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'open_dedupe_key'],
                'fpi_office_client_open_dedupe_uq'
            );
            $table->index(['office_id', 'status', 'due_at'], 'fpi_office_status_due_idx');
            $table->index(['office_id', 'client_id', 'status'], 'fpi_office_client_status_idx');
            $table->index(['office_id', 'logical_key'], 'fpi_office_logical_idx');
        });

        $this->seedCategories();
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_pending_items');
        Schema::dropIfExists('fiscal_findings');
        Schema::dropIfExists('fiscal_snapshots');
        Schema::dropIfExists('fiscal_evidence_artifacts');
        Schema::table('fiscal_last_update_events', function (Blueprint $table) {
            $table->dropForeign(['directed_run_id']);
        });
        Schema::dropIfExists('fiscal_monitoring_runs');
        Schema::dropIfExists('fiscal_last_update_events');
        Schema::dropIfExists('fiscal_monitoring_schedules');
        Schema::dropIfExists('fiscal_competences');
        Schema::dropIfExists('office_fiscal_category_links');
        Schema::dropIfExists('fiscal_categories');
    }

    private function seedCategories(): void
    {
        $now = now();
        $rows = [
            ['SIMPLES_NACIONAL', 'Simples Nacional', 'simples_mei', 'FULL', 'INTEGRA_SN', 'PGDASD', 10],
            ['MEI', 'MEI / SIMEI', 'simples_mei', 'FULL', 'INTEGRA_MEI', 'PGMEI', 20],
            ['DCTFWEB', 'DCTFWeb', 'dctfweb_mit', 'FULL', 'INTEGRA_DCTFWEB', 'DCTFWEB', 30],
            ['MIT', 'MIT', 'dctfweb_mit', 'PARTIAL', 'INTEGRA_DCTFWEB', 'MIT', 40],
            ['PARCELAMENTOS', 'Parcelamentos', 'parcelamentos', 'FULL', 'INTEGRA_PARCELAMENTO', 'PARCELAMENTO', 50],
            ['SITFIS', 'Situação Fiscal (SITFIS)', 'sitfis', 'FULL', 'INTEGRA_SITFIS', 'SITFIS', 60],
            ['CAIXA_POSTAL', 'Caixa Postal / DTE', 'mailbox', 'FULL', 'INTEGRA_CAIXAPOSTAL', 'MENSAGEM', 70],
            ['DECLARACOES', 'Declarações auxiliares', 'declaracoes', 'PARTIAL', 'INTEGRA_CONTADOR', 'DECLARACAO', 80],
            ['GUIAS', 'Guias / Pagamentos', 'guias', 'PARTIAL', 'INTEGRA_PAGAMENTO', 'GUIA', 90],
            ['FGTS', 'FGTS (parcial eSocial)', 'fgts', 'PARTIAL', 'ESOCIAL', 'FGTS', 100],
        ];

        foreach ($rows as [$code, $name, $module, $coverage, $system, $service, $sort]) {
            DB::table('fiscal_categories')->insert([
                'code' => $code,
                'name' => $name,
                'module_key' => $module,
                'default_coverage' => $coverage,
                'default_mutability' => 'READ_ONLY',
                'system_code' => $system,
                'service_code' => $service,
                'is_active' => true,
                'sort_order' => $sort,
                'description' => null,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
