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
        Schema::create('ccmei_issued_certificates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('tenant_id');
            $table->bigInteger('client_id');
            $table->string('contributor_cnpj', 14);
            $table->string('certificate_vault_object_id', 26);
            $table->char('certificate_sha256', 64);
            $table->string('certificate_mime_type', 100);
            $table->integer('certificate_byte_size');
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'client_id', 'observed_at']);
            $table->unique(['tenant_id', 'client_id', 'certificate_sha256'], 'ccmei_issued_certificates_tenant_id_client_id_cert_0ddd13477b');
            $table->index(['tenant_id', 'contributor_cnpj']);
            $table->foreign(['client_id'])->references(['id'])->on('clients')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['tenant_id'])->references(['id'])->on('tenants')->onUpdate('no action')->onDelete('restrict');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ccmei_issued_certificates');
    }
};
