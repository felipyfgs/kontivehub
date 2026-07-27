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
        Schema::create('tax_proxy_powers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('tenant_serpro_authorization_id')->nullable();
            $table->string('author_identity', 14);
            $table->string('contributor_cnpj', 14);
            $table->string('system_code', 80);
            $table->string('service_code', 120)->nullable();
            $table->string('power_code', 120);
            $table->string('source', 40);
            $table->string('status', 32);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->string('last_check_result', 80)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->string('environment', 20)->nullable();
            $table->string('provenance', 40)->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('freshness_checked_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('segregation_class', 40)->default('HISTORICAL_UNVERIFIED');

            $table->index(['tenant_id', 'client_id', 'status']);
            $table->index(['tenant_id', 'contributor_cnpj']);
            $table->index(['tenant_id', 'power_code', 'status']);
            $table->unique(['tenant_id', 'client_id', 'power_code', 'author_identity', 'source'], 'tax_proxy_powers_tenant_id_client_id_power_code_au_14d2023633');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_serpro_authorization_id'])->references(['id'])->on('tenant_serpro_authorizations')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_proxy_powers');
    }
};
