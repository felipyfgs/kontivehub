<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimento técnico versionado do escritório (uso do A1 canônico e finalidades).
 * Evidência imutável por versão; revogação via revoked_at (sem apagar histórico).
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_technical_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('version_code', 40);
            $table->json('purposes_presented');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('consented_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->string('payload_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['office_id', 'version_code', 'revoked_at'],
                'office_technical_consents_lookup'
            );
            $table->index(['office_id', 'consented_at'], 'office_technical_consents_office_when');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_technical_consents');
    }
};
