<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plano de controle global: contrato SERPRO da software house.
 * SEM office_id — um ACTIVE por ambiente no máximo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 20);
            $table->string('status', 32);
            $table->string('contractor_cnpj', 14);
            $table->string('contractor_name')->nullable();
            $table->string('subject_name')->nullable();
            $table->string('fingerprint_sha256', 64)->nullable();
            $table->timestamp('cert_valid_from')->nullable();
            $table->timestamp('cert_valid_to')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_auth_at')->nullable();
            $table->string('health_status', 40)->nullable();
            $table->string('health_message', 500)->nullable();
            /** Referências opacas ao SecureObjectStore — nunca material sensível. */
            $table->string('pfx_vault_object_id', 26)->nullable();
            $table->string('oauth_vault_object_id', 26)->nullable();
            $table->string('token_vault_object_id', 26)->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('consumer_key_hint', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['environment', 'status']);
            $table->index('contractor_cnpj');
        });

        // Índice auxiliar para buscas de ativo.
        Schema::table('serpro_contracts', function (Blueprint $table) {
            $table->index(['environment', 'status', 'id'], 'serpro_contracts_env_status_id_idx');
        });

        // Unicidade parcial: no máximo um ACTIVE por ambiente (PostgreSQL).
        // SQLite/testing: enforced no service com lockForUpdate em activate().
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                'CREATE UNIQUE INDEX serpro_contracts_one_active_per_environment
                 ON serpro_contracts (environment)
                 WHERE status = \'ACTIVE\''
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_contracts');
    }
};
