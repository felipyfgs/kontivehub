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
        Schema::create('serpro_term_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_serpro_authorization_id');
            $table->string('environment', 20);
            $table->integer('version_number');
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
            $table->bigInteger('created_by_user_id')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_serpro_authorization_id', 'version_number'], 'serpro_term_versions_tenant_serpro_authorization_i_dcff73d571');
            $table->index(['tenant_id', 'environment', 'status']);
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_serpro_authorization_id'])->references(['id'])->on('tenant_serpro_authorizations')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_term_versions');
    }
};
