<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorização SERPRO por escritório (Autor do Pedido + Termo + token).
 * Tenant-scoped: office_id obrigatório.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_serpro_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('status', 40);
            $table->string('author_identity_type', 10);
            $table->string('author_identity', 14);
            $table->string('author_name')->nullable();
            $table->string('certificate_mode', 40)->default('EXTERNAL_SIGNATURE');
            $table->boolean('managed_a1_consent')->default(false);
            $table->timestamp('managed_a1_consented_at')->nullable();
            $table->string('author_pfx_vault_object_id', 26)->nullable();
            $table->string('author_fingerprint_sha256', 64)->nullable();
            $table->timestamp('author_cert_valid_from')->nullable();
            $table->timestamp('author_cert_valid_to')->nullable();
            $table->string('termo_vault_object_id', 26)->nullable();
            $table->string('termo_sha256', 64)->nullable();
            $table->timestamp('termo_valid_from')->nullable();
            $table->timestamp('termo_valid_to')->nullable();
            $table->string('termo_destination_cnpj', 14)->nullable();
            $table->string('termo_signed_by')->nullable();
            $table->timestamp('termo_uploaded_at')->nullable();
            $table->string('procurador_token_vault_object_id', 26)->nullable();
            $table->timestamp('procurador_token_expires_at')->nullable();
            $table->timestamp('last_token_refresh_at')->nullable();
            $table->string('last_validation_result', 80)->nullable();
            $table->string('last_validation_message', 500)->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('action_required_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['office_id', 'environment'], 'office_serpro_auth_office_env_uq');
            $table->index(['office_id', 'status']);
            $table->index('author_identity');
        });

        Schema::create('office_serpro_authorization_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_serpro_authorization_id')
                ->constrained('office_serpro_authorizations')
                ->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('event', 80);
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['office_id', 'office_serpro_authorization_id'], 'osa_events_office_auth_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_serpro_authorization_events');
        Schema::dropIfExists('office_serpro_authorizations');
    }
};
