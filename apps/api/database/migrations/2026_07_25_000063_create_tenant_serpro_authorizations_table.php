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
        Schema::create('tenant_serpro_authorizations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('environment', 20);
            $table->string('status', 40);
            $table->string('author_identity_type', 10);
            $table->string('author_identity', 14)->index();
            $table->string('author_name')->nullable();
            $table->string('certificate_mode', 40)->default('EXTERNAL_SIGNATURE');
            $table->string('termo_vault_object_id', 26)->nullable();
            $table->string('termo_sha256', 64)->nullable();
            $table->timestampTz('termo_valid_from')->nullable();
            $table->timestampTz('termo_valid_to')->nullable();
            $table->string('termo_destination_cnpj', 14)->nullable();
            $table->string('termo_signed_by')->nullable();
            $table->timestampTz('termo_uploaded_at')->nullable();
            $table->string('procurador_token_vault_object_id', 26)->nullable();
            $table->timestampTz('procurador_token_expires_at')->nullable();
            $table->timestampTz('last_token_refresh_at')->nullable();
            $table->string('last_validation_result', 80)->nullable();
            $table->string('last_validation_message', 500)->nullable();
            $table->timestampTz('last_validated_at')->nullable();
            $table->string('action_required_reason', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->string('termo_authorization_state', 30)->nullable();
            $table->string('procurador_etag')->nullable();

            $table->unique(['tenant_id', 'environment']);
            $table->index(['tenant_id', 'status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_serpro_authorizations');
    }
};
