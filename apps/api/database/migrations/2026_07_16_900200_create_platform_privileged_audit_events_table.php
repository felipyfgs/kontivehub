<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha append-only de acesso privilegiado PLATFORM_ADMIN (sem OfficeMembership fictícia).
 * Sem updated_at; sem APIs de update/delete. Metadados devem ser sanitizados na escrita.
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_privileged_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->string('action', 80);
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('result', 20)->default('SUCCESS');
            $table->string('request_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'action', 'created_at'], 'ppae_office_action_created_idx');
            $table->index(['actor_user_id', 'created_at'], 'ppae_actor_created_idx');
            $table->index(['target_type', 'target_id'], 'ppae_target_idx');
            $table->index('request_id', 'ppae_request_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_privileged_audit_events');
    }
};
