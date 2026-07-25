<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versões imutáveis de credencial SERPRO global (Consumer Key/Secret + PFX).
 * Estados: PENDING|VERIFIED|ACTIVE|RETIRED|COMPROMISED.
 * Segredos somente no vault (referências opacas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_credential_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serpro_contract_id')
                ->nullable()
                ->constrained('serpro_contracts')
                ->nullOnDelete();
            $table->string('environment', 20);
            $table->unsignedInteger('version_number');
            $table->string('status', 32);
            $table->boolean('was_exposed')->default(false);
            $table->string('exposure_reason', 500)->nullable();
            $table->timestampTz('exposed_at')->nullable();
            $table->string('consumer_key_hint', 16)->nullable();
            $table->string('fingerprint_sha256', 64)->nullable();
            $table->string('contractor_cnpj', 18)->nullable();
            $table->string('subject_name')->nullable();
            $table->timestampTz('cert_valid_from')->nullable();
            $table->timestampTz('cert_valid_to')->nullable();
            /** Referências opacas ao SecureObjectStore. */
            $table->string('pfx_vault_object_id', 26)->nullable();
            $table->string('oauth_vault_object_id', 26)->nullable();
            $table->string('token_vault_object_id', 26)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('compromised_at')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->unsignedBigInteger('activated_by_user_id')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['environment', 'version_number'], 'serpro_cred_ver_env_num_uq');
            $table->index(['environment', 'status']);
            $table->index(['was_exposed', 'status']);
        });

        // No máximo uma ACTIVE por ambiente (PostgreSQL).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX serpro_cred_versions_one_active_per_env
                 ON serpro_credential_versions (environment)
                 WHERE status = \'ACTIVE\''
            );
        }

        Schema::create('serpro_credential_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serpro_credential_version_id')
                ->constrained('serpro_credential_versions')
                ->cascadeOnDelete();
            $table->string('action', 40);
            $table->unsignedBigInteger('approver_user_id');
            $table->string('approver_role', 40)->default('PLATFORM_ADMIN');
            $table->boolean('totp_verified')->default(false);
            $table->string('decision', 20);
            $table->string('reason', 500)->nullable();
            $table->timestampTz('decided_at');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['serpro_credential_version_id', 'action'], 'serpro_cred_appr_ver_action_idx');
            $table->unique(
                ['serpro_credential_version_id', 'action', 'approver_user_id'],
                'serpro_cred_appr_ver_action_user_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_credential_approvals');
        Schema::dropIfExists('serpro_credential_versions');
    }
};
