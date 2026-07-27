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
        Schema::create('serpro_credential_connection_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('serpro_credential_version_id');
            $table->string('environment', 20);
            $table->string('fingerprint_sha256', 64);
            $table->boolean('success')->default(false);
            $table->timestampTz('tested_at');
            $table->timestampTz('expires_at');
            $table->smallInteger('http_status')->nullable();
            $table->string('sanitized_message', 500)->nullable();
            $table->bigInteger('actor_user_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->boolean('invalidated')->default(false);
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 200)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['environment', 'fingerprint_sha256'], 'serpro_credential_connection_evidences_environment_a8907c1db7');
            $table->index(['serpro_credential_version_id', 'success', 'expires_at'], 'serpro_credential_connection_evidences_serpro_cred_48935d4724');
            $table->foreign(['serpro_credential_version_id'], 'serpro_credential_connection_evidences_serpro_cred_e0d4776d36')->references(['id'])->on('serpro_credential_versions')->onUpdate('no action')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_credential_connection_evidences');
    }
};
