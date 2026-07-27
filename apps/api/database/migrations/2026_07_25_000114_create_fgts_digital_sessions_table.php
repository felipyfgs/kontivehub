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
        Schema::create('fgts_digital_sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->bigInteger('representation_id')->nullable();
            $table->string('credential_source', 16);
            $table->string('credential_fingerprint', 64);
            $table->string('profile_type', 32);
            $table->string('target_identifier_hash', 64);
            $table->string('contract_version', 16)->default('1');
            $table->string('status', 32)->default('READY');
            $table->string('vault_object_id', 26)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_used_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'profile_type', 'status', 'expires_at'], 'fgts_digital_sessions_tenant_id_client_id_profile__6e0dfa6574');
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['representation_id'])->references(['id'])->on('fgts_digital_representations')->onUpdate('no action')->onDelete('set null');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fgts_digital_sessions');
    }
};
