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
        Schema::create('serpro_authorization_consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('tenant_serpro_authorization_id');
            $table->string('consent_type', 40);
            $table->string('version_code', 40);
            $table->bigInteger('actor_user_id');
            $table->timestampTz('consented_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('payload_sha256', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'tenant_serpro_authorization_id', 'consent_type'], 'serpro_authorization_consents_tenant_id_tenant_ser_76ba858e4e');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_serpro_authorization_id'], 'serpro_authorization_consents_tenant_serpro_author_89c8bda416')->references(['id'])->on('tenant_serpro_authorizations')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_authorization_consents');
    }
};
