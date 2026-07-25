<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projeções DCTFWeb/MIT (tasks 9.1–9.4, 9.8).
 * Evidências versionadas por retificação; MIT independente da transmissão DCTFWeb.
 * Parcelamentos ficam em migration futura (9.5–9.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dctfweb_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->string('period_key', 20);
            $table->string('declaration_type', 30)->default('ORIGINAL');
            $table->string('transmission_status', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('FULL');
            $table->string('receipt_number', 80)->nullable();
            $table->timestampTz('transmitted_at')->nullable();
            $table->timestampTz('official_at')->nullable();
            $table->unsignedInteger('evidence_version')->default(0);
            /** Nunca promover a PAID só por emissão de DARF. */
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->foreignId('current_snapshot_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'period_key'],
                'dctfweb_decl_office_client_period_uq'
            );
            $table->index(
                ['office_id', 'transmission_status'],
                'dctfweb_decl_office_tx_idx'
            );
            $table->index(
                ['office_id', 'client_id', 'situation'],
                'dctfweb_decl_office_client_sit_idx'
            );
        });

        Schema::create('dctfweb_evidence_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('declaration_id')->nullable()
                ->constrained('dctfweb_declarations')->nullOnDelete();
            $table->foreignId('competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('run_id')->nullable()
                ->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('evidence_artifact_id')
                ->constrained('fiscal_evidence_artifacts')->cascadeOnDelete();
            $table->string('artifact_kind', 40);
            $table->unsignedInteger('version');
            $table->string('content_sha256', 64);
            $table->boolean('is_current')->default(true);
            $table->string('declaration_type', 30)->nullable();
            $table->string('source_version', 40)->nullable();
            $table->boolean('is_retification')->default(false);
            $table->timestampTz('observed_at');
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['office_id', 'declaration_id', 'artifact_kind', 'version'],
                'dctfweb_ev_decl_kind_ver_uq'
            );
            $table->index(
                ['office_id', 'declaration_id', 'artifact_kind', 'is_current'],
                'dctfweb_ev_current_idx'
            );
            $table->index(
                ['office_id', 'content_sha256'],
                'dctfweb_ev_office_sha_idx'
            );
        });

        Schema::create('dctfweb_darf_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('declaration_id')->nullable()
                ->constrained('dctfweb_declarations')->nullOnDelete();
            $table->foreignId('competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->foreignId('evidence_version_id')->nullable()
                ->constrained('dctfweb_evidence_versions')->nullOnDelete();
            $table->foreignId('evidence_artifact_id')->nullable()
                ->constrained('fiscal_evidence_artifacts')->nullOnDelete();
            $table->string('document_number', 80)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            /** Emissão ≠ pagamento. */
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->string('content_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'content_sha256'],
                'dctfweb_darf_office_sha_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'declaration_id'],
                'dctfweb_darf_office_client_decl_idx'
            );
        });

        Schema::create('mit_apuracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->string('period_key', 20);
            $table->string('encerramento_status', 30)->default('UNKNOWN');
            $table->string('situacao_status', 30)->default('UNKNOWN');
            /**
             * Espelho honesto da transmissão DCTFWeb (não inferido do MIT).
             * Encerrado MIT sem recibo → dctfweb_transmission_status permanece UNKNOWN/PENDING.
             */
            $table->string('dctfweb_transmission_status', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('coverage', 30)->default('PARTIAL');
            $table->timestampTz('encerrado_at')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->foreignId('current_snapshot_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'period_key'],
                'mit_apur_office_client_period_uq'
            );
            $table->index(
                ['office_id', 'encerramento_status'],
                'mit_apur_office_enc_idx'
            );
        });

        Schema::create('dctfweb_mutation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competence_id')->nullable()
                ->constrained('fiscal_competences')->nullOnDelete();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('period_key', 20)->nullable();
            $table->string('idempotency_key', 160);
            $table->string('status', 32)->default('PENDING');
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('blocked_retry_until')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'idempotency_key'],
                'dctfweb_mut_office_idem_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'status'],
                'dctfweb_mut_office_client_status_idx'
            );
            $table->index(
                ['office_id', 'status', 'blocked_retry_until'],
                'dctfweb_mut_block_idx'
            );
        });

        $this->seedCatalogOps();
    }

    public function down(): void
    {
        Schema::dropIfExists('dctfweb_mutation_attempts');
        Schema::dropIfExists('mit_apuracoes');
        Schema::dropIfExists('dctfweb_darf_documents');
        Schema::dropIfExists('dctfweb_evidence_versions');
        Schema::dropIfExists('dctfweb_declarations');
    }

    private function seedCatalogOps(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }

        $now = now();
        $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);

        $ops = [
            // solution, service, operation, label, mutating, power, billable, cache
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'MONITOR', 'Reconciliação DCTFWeb por evento', false, 'DCTFWEB', 'CONSULTA', 1800],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'CONSULTAR_DECLARACAO', 'Consultar declaração DCTFWeb', false, 'DCTFWEB', 'CONSULTA', 1800],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'CONSULTAR_RELATORIO', 'Consultar relatório completo DCTFWeb', false, 'DCTFWEB', 'CONSULTA', 1800],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'CONSULTAR_XML', 'Consultar XML DCTFWeb', false, 'DCTFWEB', 'CONSULTA', 1800],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'EMITIR_DARF', 'Emitir documento de arrecadação DCTFWeb', false, 'DCTFWEB', 'EMISSAO', 0],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'TRANSMITIR_DECLARACAO', 'Transmitir DCTFWeb', true, 'DCTFWEB', 'DECLARACAO', 0],
            ['INTEGRA_MIT', 'MIT', 'CONSULTAR_APURACAO', 'Consultar apuração MIT', false, 'MIT', 'CONSULTA', 1800],
            ['INTEGRA_MIT', 'MIT', 'ENCERRAR', 'Encerrar MIT', true, 'MIT', 'DECLARACAO', 0],
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
                    'rate_limit_per_minute' => $mutating ? 10 : 30,
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
