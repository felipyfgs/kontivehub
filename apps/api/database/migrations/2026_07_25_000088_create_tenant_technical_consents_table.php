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
        Schema::create('tenant_technical_consents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->string('version_code', 40);
            $table->jsonb('purposes_presented');
            $table->bigInteger('actor_user_id');
            $table->timestampTz('consented_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('payload_sha256', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'version_code', 'revoked_at'], 'tenant_technical_consents_tenant_id_version_code_r_3750187139');
            $table->index(['tenant_id', 'consented_at']);
            $table->foreign(['actor_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_technical_consents');
    }
};
