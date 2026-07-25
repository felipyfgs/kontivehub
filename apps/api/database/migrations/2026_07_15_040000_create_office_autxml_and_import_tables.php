<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema do canal NFE_AUTXML_DISTDFE e import assíncrono em massa.
 *
 * document_acquisitions: ownership da change MA (2026_07_15_030000);
 * esta migration apenas estende a tabela existente.
 *
 * @see docs/ops/autxml-document-acquisitions-ownership.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_fiscal_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('cnpj', 14); // completo uppercase, numérico ou alfanumérico
            $table->string('root_cnpj', 8); // raiz derivada
            $table->string('status', 32)->default('ACTIVE'); // ACTIVE | INACTIVE
            $table->string('legal_name')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'cnpj'], 'office_fiscal_identities_office_cnpj_unique');
            $table->index(['office_id', 'root_cnpj']);
            $table->index(['office_id', 'status']);
        });

        Schema::create('office_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_fiscal_identity_id')->constrained('office_fiscal_identities')->cascadeOnDelete();
            $table->string('purpose', 40)->default('NFE_AUTXML_DISTDFE');
            $table->string('status', 32); // CredentialStatus
            $table->string('subject_name');
            $table->string('holder_cnpj', 14);
            $table->string('fingerprint_sha256', 64);
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');
            $table->string('vault_object_id', 26);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->boolean('expires_alert_30')->default(false);
            $table->boolean('expires_alert_7')->default(false);
            $table->boolean('expires_alert_1')->default(false);
            $table->timestamps();

            $table->index(['office_fiscal_identity_id', 'purpose', 'status'], 'office_credentials_identity_purpose_status');
            $table->index(['office_id', 'valid_to']);
            $table->unique(
                ['office_fiscal_identity_id', 'fingerprint_sha256', 'status'],
                'office_credentials_fp_status_unique'
            );
        });

        // No máximo uma ACTIVE por identidade+finalidade (partial index no Postgres;
        // no SQLite a aplicação e testes reforçam a regra).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX office_credentials_one_active_per_purpose
                 ON office_credentials (office_fiscal_identity_id, purpose)
                 WHERE status = \'ACTIVE\''
            );
        }

        Schema::create('office_autxml_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_fiscal_identity_id')->constrained('office_fiscal_identities')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('PENDING'); // PENDING | CONFIRMED | INACTIVE
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_fiscal_identity_id', 'establishment_id'],
                'office_autxml_enrollments_identity_estab_unique'
            );
            $table->index(['office_id', 'status']);
            $table->index(['establishment_id', 'status']);
        });

        Schema::create('office_distribution_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_fiscal_identity_id')->constrained('office_fiscal_identities')->cascadeOnDelete();
            $table->string('interested_root_cnpj', 8);
            $table->string('query_cnpj', 14); // CNPJ completo canônico do pedido
            $table->string('environment', 40);
            $table->string('channel', 40)->default('NFE_AUTXML_DISTDFE');
            $table->unsignedBigInteger('last_nsu')->default(0);
            $table->unsignedBigInteger('max_nsu_seen')->nullable();
            $table->string('status', 32)->default('IDLE');
            $table->string('last_cstat', 10)->nullable();
            $table->string('last_xmotivo', 255)->nullable();
            $table->unsignedInteger('consecutive_decode_failures')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('next_sync_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->string('lock_owner')->nullable();
            $table->string('external_consumer_status', 40)->nullable(); // null | DECLARED_CLEAR | EXTERNAL_CONSUMER_CONFLICT
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'interested_root_cnpj', 'environment', 'channel'],
                'office_distribution_cursors_stream_unique'
            );
            $table->index(['status', 'next_sync_at']);
            $table->index(['office_id', 'channel']);
        });

        Schema::create('office_distribution_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_distribution_cursor_id')->constrained('office_distribution_cursors')->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('trigger', 20)->default('SCHEDULED');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('from_nsu')->default(0);
            $table->unsignedBigInteger('to_nsu')->default(0);
            $table->unsignedInteger('pages_processed')->default(0);
            $table->unsignedInteger('documents_persisted')->default(0);
            $table->unsignedInteger('documents_quarantined')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_cstat', 10)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable(); // sanitizado
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'created_at']);
            $table->index(['office_distribution_cursor_id', 'created_at']);
        });

        Schema::create('fiscal_document_quarantine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('sha256', 64);
            $table->string('vault_object_id', 26);
            $table->unsignedInteger('byte_size');
            $table->string('access_key', 50)->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            $table->string('recipient_cnpj', 14)->nullable();
            $table->string('model', 5)->nullable();
            $table->string('schema_family', 40)->nullable();
            $table->string('reason', 60); // tipado
            $table->string('source', 40); // AUTXML_DIST_NSU | MANUAL_XML | MANUAL_ZIP | …
            $table->string('channel', 40)->nullable();
            $table->unsignedBigInteger('nsu')->nullable();
            $table->foreignId('office_distribution_cursor_id')->nullable()
                ->constrained('office_distribution_cursors')->nullOnDelete();
            $table->foreignId('document_import_batch_item_id')->nullable(); // FK adiantada sem constrained (tabela depois)
            $table->string('resolution_status', 20)->default('OPEN'); // OPEN | RESOLVED | DISMISSED
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_code', 60)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('promoted_dfe_document_id')->nullable()
                ->constrained('dfe_documents')->nullOnDelete();
            $table->json('metadata')->nullable(); // sem XML / segredos
            $table->timestamps();

            $table->unique(['office_id', 'sha256', 'source', 'nsu'], 'fiscal_quarantine_office_sha_source_nsu');
            $table->index(['office_id', 'resolution_status', 'reason']);
            $table->index(['office_id', 'access_key']);
        });

        Schema::create('document_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // restrição opcional
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 40)->default('UPLOADED');
            $table->string('idempotency_key', 80)->nullable();
            $table->string('selection_digest', 64)->nullable();
            $table->unsignedInteger('file_count')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('quarantined_count')->default(0);
            $table->unsignedBigInteger('compressed_bytes')->default(0);
            $table->unsignedBigInteger('uncompressed_bytes')->default(0);
            $table->string('spool_vault_object_id', 26)->nullable(); // opaco; hidden na API
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('spool_expires_at')->nullable();
            $table->json('quotas')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'idempotency_key'], 'document_import_batches_office_idem_unique');
            $table->index(['office_id', 'status', 'created_at']);
            $table->index(['office_id', 'selection_digest']);
        });

        Schema::create('document_import_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_import_batch_id')->constrained('document_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('item_index')->default(0);
            $table->string('source_name', 255); // sanitizado
            $table->string('entry_name', 255)->nullable(); // path interno ZIP sanitizado
            $table->string('sha256', 64)->nullable();
            $table->string('access_key', 50)->nullable();
            $table->string('model', 5)->nullable();
            $table->string('issuer_cnpj', 14)->nullable();
            $table->foreignId('establishment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dfe_document_id')->nullable()->constrained('dfe_documents')->nullOnDelete();
            $table->string('status', 40)->default('PENDING');
            $table->string('result_code', 60)->nullable();
            $table->string('result_message', 500)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('byte_size')->nullable();
            $table->string('spool_vault_object_id', 26)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_import_batch_id', 'item_index'],
                'document_import_batch_items_batch_index_unique'
            );
            $table->index(['office_id', 'status']);
            $table->index(['document_import_batch_id', 'status']);
            $table->index(['office_id', 'access_key']);
        });

        // FK tardia da quarentena → batch item
        Schema::table('fiscal_document_quarantine', function (Blueprint $table) {
            $table->foreign('document_import_batch_item_id', 'fiscal_quarantine_batch_item_fk')
                ->references('id')->on('document_import_batch_items')->nullOnDelete();
        });

        // Extensão de document_acquisitions (owned pela change MA) — idempotente
        if (! Schema::hasColumn('document_acquisitions', 'nsu')) {
            Schema::table('document_acquisitions', function (Blueprint $table) {
                $table->unsignedBigInteger('nsu')->nullable()->after('channel');
                $table->foreignId('office_distribution_cursor_id')->nullable()->after('outbound_number_state_id')
                    ->constrained('office_distribution_cursors')->nullOnDelete();
                $table->foreignId('document_import_batch_item_id')->nullable()->after('office_distribution_cursor_id')
                    ->constrained('document_import_batch_items')->nullOnDelete();
                $table->index(['office_id', 'nsu'], 'document_acquisitions_office_nsu');
                $table->index(['document_import_batch_item_id'], 'document_acquisitions_batch_item');
            });
        }

        // document_interests: direção + NSU opcional + idempotência por papel/canal
        if (! Schema::hasColumn('document_interests', 'direction')) {
            Schema::table('document_interests', function (Blueprint $table) {
                $table->string('direction', 10)->nullable()->after('fiscal_role');
            });

            // Tornar nsu nullable (imports sem NSU real / task 8.10 — sem CRC sintético)
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                Schema::getConnection()->statement(
                    'ALTER TABLE document_interests ALTER COLUMN nsu DROP NOT NULL'
                );
            } elseif ($driver === 'sqlite') {
                // Laravel reescreve a tabela em memória; obrigatório para RefreshDatabase nos testes.
                Schema::table('document_interests', function (Blueprint $table) {
                    $table->unsignedBigInteger('nsu')->nullable()->change();
                });
            }

            // Unique por documento+estab+papel+canal
            Schema::table('document_interests', function (Blueprint $table) {
                $table->dropUnique(['dfe_document_id', 'establishment_id']);
            });
            Schema::table('document_interests', function (Blueprint $table) {
                $table->unique(
                    ['dfe_document_id', 'establishment_id', 'fiscal_role', 'channel'],
                    'document_interests_doc_estab_role_channel_unique'
                );
                $table->index(['office_id', 'direction'], 'document_interests_office_direction');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Remove FKs que apontam para as tabelas novas antes de dropá-las.
        if ($driver === 'pgsql') {
            Schema::table('document_acquisitions', function (Blueprint $table) {
                $table->dropIndex('document_acquisitions_office_nsu');
                $table->dropIndex('document_acquisitions_batch_item');
                $table->dropConstrainedForeignId('document_import_batch_item_id');
                $table->dropConstrainedForeignId('office_distribution_cursor_id');
                $table->dropColumn('nsu');
            });

            Schema::table('document_interests', function (Blueprint $table) {
                $table->dropIndex('document_interests_office_direction');
                $table->dropUnique('document_interests_doc_estab_role_channel_unique');
            });
            Schema::table('document_interests', function (Blueprint $table) {
                $table->unique(['dfe_document_id', 'establishment_id']);
                $table->dropColumn('direction');
            });
        }
        // SQLite (testes): colunas extras em document_acquisitions/interests ficam
        // órfãs se down parcial; RefreshDatabase recria o schema do zero.

        Schema::dropIfExists('document_import_batch_items');
        Schema::dropIfExists('document_import_batches');
        Schema::dropIfExists('fiscal_document_quarantine');
        Schema::dropIfExists('office_distribution_runs');
        Schema::dropIfExists('office_distribution_cursors');
        Schema::dropIfExists('office_autxml_enrollments');

        if ($driver === 'pgsql') {
            Schema::getConnection()->statement('DROP INDEX IF EXISTS office_credentials_one_active_per_purpose');
        }

        Schema::dropIfExists('office_credentials');
        Schema::dropIfExists('office_fiscal_identities');
    }
};
