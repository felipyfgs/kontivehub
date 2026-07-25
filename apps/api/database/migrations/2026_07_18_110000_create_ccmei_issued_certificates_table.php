<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ccmei_issued_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('contributor_cnpj', 14);
            $table->string('certificate_vault_object_id', 26);
            $table->char('certificate_sha256', 64);
            $table->string('certificate_mime_type', 100);
            $table->unsignedInteger('certificate_byte_size');
            $table->string('source_provenance', 32);
            $table->timestampTz('observed_at');
            $table->timestamps();

            $table->unique(['office_id', 'client_id', 'certificate_sha256'], 'ccmei_issued_certificates_office_client_sha_uq');
            $table->index(['office_id', 'client_id', 'observed_at'], 'ccmei_issued_certificates_client_observed_idx');
            $table->index(['office_id', 'contributor_cnpj'], 'ccmei_issued_certificates_office_cnpj_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ccmei_issued_certificates');
    }
};
