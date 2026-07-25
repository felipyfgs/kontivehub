<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciais de integração do escritório (EMITTER_PUSH).
 * Token exibido uma única vez; persistido somente como hash.
 *
 * @see openspec/changes/complete-cte-capture-with-distdfe-autxml-and-import
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_integration_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('token_prefix', 12); // para identificação sem revelar segredo
            $table->string('token_hash', 64); // sha256 do token
            $table->string('scope', 40)->default('cte:ingest');
            $table->string('status', 32)->default('ACTIVE'); // ACTIVE | REVOKED | EXPIRED
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['office_id', 'token_hash'], 'office_integration_tokens_office_hash');
            $table->index(['office_id', 'status']);
            $table->index(['token_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_integration_tokens');
    }
};
