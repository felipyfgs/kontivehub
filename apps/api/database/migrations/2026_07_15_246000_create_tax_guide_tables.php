<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central de guias fiscais (tasks 11.6–11.10).
 *
 * - tax_guides: identidade lógica tenant-scoped (débito/competência)
 * - tax_guide_versions: histórico imutável de emissões/substituições
 * - tax_guide_download_tokens: download temporário sem path/segredo
 * - tax_guide_payment_confirmations: pagamento oficial (independente de emissão/download)
 *
 * Mutações desabilitadas por default (FeatureFlags guias/mutating).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_guides')) {
            Schema::create('tax_guides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
                $table->string('system_code', 40);
                $table->string('service_code', 80);
                $table->string('operation_code', 80)->default('EMITIR_GUIA');
                $table->string('competence_period_key', 20)->nullable(); // YYYY-MM | YYYY
                $table->string('debit_ref', 120)->nullable(); // identificador lógico do débito
                /** Chave estável tenant+cliente+serviço+competência+débito. */
                $table->string('logical_key', 180);
                $table->unsignedBigInteger('current_version_id')->nullable();
                /**
                 * Estado de pagamento — NUNCA inferido por emissão/download.
                 * UNKNOWN | NOT_CONFIRMED | CONFIRMED | PARTIAL
                 */
                $table->string('payment_status', 30)->default('UNKNOWN');
                $table->timestampTz('payment_confirmed_at')->nullable();
                $table->string('payment_source', 80)->nullable();
                $table->string('payment_external_id', 160)->nullable();
                // Projeções da versão vigente (facilita listagem)
                $table->unsignedBigInteger('amount_cents')->nullable();
                $table->string('currency', 3)->default('BRL');
                $table->timestampTz('due_at')->nullable();
                $table->string('identifier_code', 120)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['office_id', 'logical_key'], 'tg_office_logical_uq');
                $table->index(['office_id', 'client_id', 'payment_status'], 'tg_office_client_pay_idx');
                $table->index(['office_id', 'due_at'], 'tg_office_due_idx');
                $table->index(['office_id', 'system_code', 'service_code'], 'tg_office_sys_svc_idx');
            });
        }

        if (! Schema::hasTable('tax_guide_versions')) {
            Schema::create('tax_guide_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tax_guide_id')->constrained('tax_guides')->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->boolean('is_current')->default(false);
                /**
                 * PENDING | SENT | CONFIRMED | REJECTED | UNKNOWN_RESULT | RECONCILING
                 * | EXPIRED | CANCELLED | SUPERSEDED
                 */
                $table->string('emission_status', 30)->default('PENDING');
                $table->unsignedBigInteger('replaces_version_id')->nullable();
                $table->unsignedBigInteger('superseded_by_version_id')->nullable();
                $table->string('identifier_code', 120)->nullable();
                $table->unsignedBigInteger('amount_cents')->nullable();
                $table->string('currency', 3)->default('BRL');
                $table->timestampTz('due_at')->nullable();
                $table->timestampTz('valid_until')->nullable();
                $table->string('content_sha256', 64)->nullable();
                $table->string('vault_object_id', 26)->nullable();
                $table->string('content_type', 80)->nullable();
                $table->unsignedBigInteger('byte_size')->default(0);
                $table->string('idempotency_key', 160);
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('usage_reservation_id')->nullable();
                $table->string('remote_protocol', 160)->nullable();
                $table->string('risk_level', 20)->default('HIGH');
                $table->json('confirmation_summary')->nullable();
                $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('confirmed_at')->nullable();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('sent_at')->nullable();
                $table->timestampTz('finished_at')->nullable();
                $table->timestampTz('reconcile_after')->nullable();
                $table->unsignedSmallInteger('reconcile_attempts')->default(0);
                $table->string('error_code', 80)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->json('metadata')->nullable(); // sem path/segredo/payload fiscal bruto
                $table->timestamps();

                $table->unique(['office_id', 'idempotency_key'], 'tgv_office_idem_uq');
                $table->unique(['office_id', 'tax_guide_id', 'version_number'], 'tgv_office_guide_ver_uq');
                $table->index(['office_id', 'tax_guide_id', 'is_current'], 'tgv_office_guide_current_idx');
                $table->index(['office_id', 'emission_status', 'reconcile_after'], 'tgv_office_status_recon_idx');
            });
        }

        if (Schema::hasTable('tax_guides') && Schema::hasTable('tax_guide_versions')
            && Schema::hasColumn('tax_guides', 'current_version_id')) {
            try {
                Schema::table('tax_guides', function (Blueprint $table) {
                    $table->foreign('current_version_id')
                        ->references('id')
                        ->on('tax_guide_versions')
                        ->nullOnDelete();
                });
            } catch (Throwable) {
                // FK já existe
            }
        }

        if (Schema::hasTable('tax_guide_versions')) {
            try {
                Schema::table('tax_guide_versions', function (Blueprint $table) {
                    $table->foreign('replaces_version_id')
                        ->references('id')
                        ->on('tax_guide_versions')
                        ->nullOnDelete();
                    $table->foreign('superseded_by_version_id')
                        ->references('id')
                        ->on('tax_guide_versions')
                        ->nullOnDelete();
                });
            } catch (Throwable) {
                // FKs já existem
            }
        }

        if (! Schema::hasTable('tax_guide_download_tokens')) {
            Schema::create('tax_guide_download_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tax_guide_version_id')->constrained('tax_guide_versions')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash', 64);
                $table->timestampTz('expires_at');
                $table->timestampTz('used_at')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(['office_id', 'token_hash'], 'tgdt_office_token_uq');
                $table->index(['office_id', 'expires_at'], 'tgdt_office_exp_idx');
            });
        }

        if (! Schema::hasTable('tax_guide_payment_confirmations')) {
            Schema::create('tax_guide_payment_confirmations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('office_id')->constrained()->cascadeOnDelete();
                $table->foreignId('tax_guide_id')->constrained('tax_guides')->cascadeOnDelete();
                $table->foreignId('tax_guide_version_id')->nullable()
                    ->constrained('tax_guide_versions')->nullOnDelete();
                $table->string('source', 80); // INTEGRA_PAGAMENTO | DCTFWEB | etc.
                $table->string('external_id', 160);
                $table->unsignedBigInteger('amount_cents')->nullable();
                $table->string('currency', 3)->default('BRL');
                $table->timestampTz('paid_at')->nullable();
                $table->string('content_sha256', 64)->nullable();
                $table->string('vault_object_id', 26)->nullable();
                $table->string('content_type', 80)->nullable();
                $table->unsignedBigInteger('byte_size')->default(0);
                /** Digest estável source|external_id para idempotência. */
                $table->string('evidence_digest', 64);
                $table->json('metadata')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(['office_id', 'evidence_digest'], 'tgpc_office_digest_uq');
                $table->unique(['office_id', 'source', 'external_id'], 'tgpc_office_src_ext_uq');
                $table->index(['office_id', 'tax_guide_id'], 'tgpc_office_guide_idx');
            });
        }

        // FK adiada de parcelamentos (242000) → tax_guides
        if (Schema::hasTable('tax_installment_parcels')
            && Schema::hasTable('tax_guides')
            && Schema::hasColumn('tax_installment_parcels', 'tax_guide_id')
        ) {
            try {
                Schema::table('tax_installment_parcels', function (Blueprint $table) {
                    $table->foreign('tax_guide_id')
                        ->references('id')
                        ->on('tax_guides')
                        ->nullOnDelete();
                });
            } catch (Throwable) {
                // FK já existe
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_guide_payment_confirmations');
        Schema::dropIfExists('tax_guide_download_tokens');
        if (Schema::hasTable('tax_guides') && Schema::hasColumn('tax_guides', 'current_version_id')) {
            try {
                Schema::table('tax_guides', function (Blueprint $table) {
                    $table->dropForeign(['current_version_id']);
                });
            } catch (Throwable) {
                // FK pode não existir se a tabela veio do stub de parcelamentos.
            }
        }
        if (Schema::hasTable('tax_guide_versions')) {
            Schema::table('tax_guide_versions', function (Blueprint $table) {
                $table->dropForeign(['replaces_version_id']);
                $table->dropForeign(['superseded_by_version_id']);
            });
        }
        Schema::dropIfExists('tax_guide_versions');
        if (Schema::hasTable('tax_installment_parcels') && Schema::hasColumn('tax_installment_parcels', 'tax_guide_id')) {
            try {
                Schema::table('tax_installment_parcels', function (Blueprint $table) {
                    $table->dropForeign(['tax_guide_id']);
                });
            } catch (Throwable) {
            }
        }
        Schema::dropIfExists('tax_guides');
    }
};
