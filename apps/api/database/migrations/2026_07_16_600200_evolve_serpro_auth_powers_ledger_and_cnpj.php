<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evolução aditiva: Termos versionados, poderes com freshness, ledger canônico,
 * budgets/ciclos e identidades CNPJ alfanuméricas (string até 18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_term_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_serpro_authorization_id')
                ->constrained('office_serpro_authorizations')
                ->cascadeOnDelete();
            $table->string('environment', 20);
            $table->unsignedInteger('version_number');
            $table->string('status', 40);
            $table->string('author_identity', 18);
            $table->string('destination_cnpj', 18)->nullable();
            $table->string('termo_sha256', 64)->nullable();
            $table->string('termo_vault_object_id', 26)->nullable();
            $table->string('signature_mode', 40)->nullable();
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->timestampTz('serpro_accepted_at')->nullable();
            $table->string('etag_vault_object_id', 26)->nullable();
            $table->string('token_vault_object_id', 26)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_serpro_authorization_id', 'version_number'],
                'serpro_term_ver_auth_num_uq'
            );
            $table->index(['office_id', 'environment', 'status']);
        });

        Schema::create('serpro_authorization_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_serpro_authorization_id')
                ->constrained('office_serpro_authorizations')
                ->cascadeOnDelete();
            $table->string('consent_type', 40);
            $table->string('version_code', 40);
            $table->unsignedBigInteger('actor_user_id');
            $table->timestampTz('consented_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('payload_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['office_id', 'office_serpro_authorization_id', 'consent_type'],
                'serpro_auth_consent_lookup_idx'
            );
        });

        if (Schema::hasTable('tax_proxy_powers')) {
            Schema::table('tax_proxy_powers', function (Blueprint $table) {
                if (! Schema::hasColumn('tax_proxy_powers', 'environment')) {
                    $table->string('environment', 20)->nullable()->after('office_id');
                }
                if (! Schema::hasColumn('tax_proxy_powers', 'provenance')) {
                    $table->string('provenance', 40)->nullable()->after('source');
                }
                if (! Schema::hasColumn('tax_proxy_powers', 'accepted_at')) {
                    $table->timestampTz('accepted_at')->nullable();
                }
                if (! Schema::hasColumn('tax_proxy_powers', 'freshness_checked_at')) {
                    $table->timestampTz('freshness_checked_at')->nullable();
                }
                if (! Schema::hasColumn('tax_proxy_powers', 'closed_at')) {
                    $table->timestampTz('closed_at')->nullable();
                }
                if (! Schema::hasColumn('tax_proxy_powers', 'segregation_class')) {
                    $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
                }
            });
        }

        if (Schema::hasTable('serpro_api_usage_reservations')) {
            Schema::table('serpro_api_usage_reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'environment')) {
                    $table->string('environment', 20)->nullable()->after('office_id');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'serpro_contract_id')) {
                    $table->unsignedBigInteger('serpro_contract_id')->nullable()->after('environment');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'attempt_state')) {
                    $table->string('attempt_state', 30)->nullable()->after('status');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'catalog_revision')) {
                    $table->string('catalog_revision', 80)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'price_revision')) {
                    $table->string('price_revision', 80)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'remote_state')) {
                    $table->string('remote_state', 40)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'durable_result_ref')) {
                    $table->string('durable_result_ref', 64)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'segregation_class')) {
                    $table->string('segregation_class', 40)->default('SHADOW');
                }
            });
        }

        if (Schema::hasTable('serpro_api_usage_entries')) {
            Schema::table('serpro_api_usage_entries', function (Blueprint $table) {
                if (! Schema::hasColumn('serpro_api_usage_entries', 'environment')) {
                    $table->string('environment', 20)->nullable()->after('office_id');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'serpro_contract_id')) {
                    $table->unsignedBigInteger('serpro_contract_id')->nullable()->after('environment');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'attempt_state')) {
                    $table->string('attempt_state', 30)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'catalog_revision')) {
                    $table->string('catalog_revision', 80)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'price_revision')) {
                    $table->string('price_revision', 80)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'remote_state')) {
                    $table->string('remote_state', 40)->nullable();
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'segregation_class')) {
                    $table->string('segregation_class', 40)->default('SHADOW');
                }
            });
        }

        Schema::create('serpro_billing_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_code', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('label', 120)->nullable();
            $table->string('status', 32)->default('OPEN');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
        });

        Schema::create('serpro_usage_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('environment', 20)->default('PRODUCTION');
            $table->string('budget_kind', 40)->default('MONETARY');
            $table->unsignedBigInteger('limit_micros');
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->unsignedBigInteger('consumed_micros')->default(0);
            $table->string('cycle_code', 40)->nullable();
            $table->string('operation_key', 120)->nullable();
            $table->boolean('is_canary')->default(false);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scope', 'environment', 'is_active'], 'serpro_budget_scope_env_act_idx');
            $table->index(['office_id', 'is_active']);
        });

        Schema::create('serpro_usage_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('severity', 20)->default('OPEN');
            $table->string('environment', 20)->nullable();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cycle_code', 40)->nullable();
            $table->string('sanitized_summary', 500);
            $table->unsignedBigInteger('expected_micros')->nullable();
            $table->unsignedBigInteger('observed_micros')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'severity']);
        });

        Schema::create('vault_object_journal', function (Blueprint $table) {
            $table->id();
            $table->string('object_id', 26)->unique();
            $table->string('purpose', 60);
            $table->unsignedInteger('crypto_key_version')->default(1);
            $table->string('rewrap_status', 20)->default('CURRENT');
            $table->string('retention_class', 40)->nullable();
            $table->timestampTz('retain_until')->nullable();
            $table->timestampTz('orphaned_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'rewrap_status']);
            $table->index(['orphaned_at', 'deleted_at']);
        });

        // CNPJ alfanumérico: colunas existentes já são string (não numéricas).
        // Ampliação de largura fica para migration dedicada com dual-write se a RFB
        // publicar formato >14; o domínio Cnpj já rejeita coerção numérica.

        if (Schema::hasTable('serpro_contracts')) {
            Schema::table('serpro_contracts', function (Blueprint $table) {
                if (! Schema::hasColumn('serpro_contracts', 'active_credential_version_id')) {
                    $table->unsignedBigInteger('active_credential_version_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('serpro_contracts', 'credentials_exposed')) {
                    $table->boolean('credentials_exposed')->default(true)->after('status');
                }
                if (! Schema::hasColumn('serpro_contracts', 'segregation_class')) {
                    $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
                }
            });
        }

        if (Schema::hasTable('offices')) {
            Schema::table('offices', function (Blueprint $table) {
                if (! Schema::hasColumn('offices', 'serpro_segregation_class')) {
                    $table->string('serpro_segregation_class', 40)->nullable()->after('is_active');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('offices') && Schema::hasColumn('offices', 'serpro_segregation_class')) {
            Schema::table('offices', function (Blueprint $table) {
                $table->dropColumn('serpro_segregation_class');
            });
        }

        if (Schema::hasTable('serpro_contracts')) {
            Schema::table('serpro_contracts', function (Blueprint $table) {
                $cols = array_filter([
                    Schema::hasColumn('serpro_contracts', 'active_credential_version_id') ? 'active_credential_version_id' : null,
                    Schema::hasColumn('serpro_contracts', 'credentials_exposed') ? 'credentials_exposed' : null,
                    Schema::hasColumn('serpro_contracts', 'segregation_class') ? 'segregation_class' : null,
                ]);
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        Schema::dropIfExists('vault_object_journal');
        Schema::dropIfExists('serpro_usage_incidents');
        Schema::dropIfExists('serpro_usage_budgets');
        Schema::dropIfExists('serpro_billing_cycles');
        Schema::dropIfExists('serpro_authorization_consents');
        Schema::dropIfExists('serpro_term_versions');
    }
};
