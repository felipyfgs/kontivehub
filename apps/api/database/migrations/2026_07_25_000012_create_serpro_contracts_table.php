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
        Schema::create('serpro_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('environment', 20)->unique();
            $table->string('status', 32);
            $table->string('contractor_cnpj', 14)->index();
            $table->string('contractor_name')->nullable();
            $table->string('subject_name')->nullable();
            $table->string('fingerprint_sha256', 64)->nullable();
            $table->timestampTz('cert_valid_from')->nullable();
            $table->timestampTz('cert_valid_to')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('blocked_at')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
            $table->timestampTz('last_auth_at')->nullable();
            $table->string('health_status', 40)->nullable();
            $table->string('health_message', 500)->nullable();
            $table->string('pfx_vault_object_id', 26)->nullable();
            $table->string('oauth_vault_object_id', 26)->nullable();
            $table->string('token_vault_object_id', 26)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->string('consumer_key_hint', 16)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->bigInteger('active_credential_version_id')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
            $table->string('trial_bearer_vault_object_id', 26)->nullable();

            $table->index(['environment', 'status', 'id']);
            $table->index(['environment', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_contracts');
    }
};
