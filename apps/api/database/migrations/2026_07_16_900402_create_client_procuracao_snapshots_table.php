<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Official procuracao projection per client (F-3.3).
 * Only sync from official SERPRO adapter — no manual override path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_procuracao_snapshots')) {
            return;
        }

        Schema::create('client_procuracao_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('environment', 32);
            $table->string('status', 32)->default('unverified');
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('evidence_ref', 120)->nullable();
            $table->json('power_codes')->nullable();
            $table->string('last_check_result', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'environment'],
                'client_procuracao_office_client_env_uq',
            );
            $table->index(['office_id', 'status'], 'client_procuracao_office_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_procuracao_snapshots');
    }
};
