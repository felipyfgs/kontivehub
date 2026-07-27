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
        Schema::create('serpro_credential_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('serpro_contract_id')->nullable();
            $table->string('environment', 20)->unique();
            $table->integer('version_number');
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
            $table->string('pfx_vault_object_id', 26)->nullable();
            $table->string('oauth_vault_object_id', 26)->nullable();
            $table->string('token_vault_object_id', 26)->nullable();
            $table->timestampTz('token_expires_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('compromised_at')->nullable();
            $table->bigInteger('verified_by_user_id')->nullable();
            $table->bigInteger('activated_by_user_id')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['environment', 'version_number']);
            $table->index(['environment', 'status']);
            $table->index(['was_exposed', 'status']);
            $table->foreign(['serpro_contract_id'])->references(['id'])->on('serpro_contracts')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_credential_versions');
    }
};
