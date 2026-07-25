<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger comercial de consultas de monitor (unidade lógica client+monitor+período).
 * Separado do ledger técnico serpro_api_usage_*; uma unidade comercial pode correlacionar
 * várias chamadas técnicas. Não altera nem substitui o ledger SERPRO técnico.
 *
 * Unicidades:
 * - idempotency_key global
 * - inaugural única por office+client+monitor
 * - scheduled única por office+client+monitor+period_key
 *
 * @see openspec/changes/separar-configuracao-escritorio-plataforma-serpro
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_commercial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('monitor_key', 40);
            $table->string('origin', 20);
            $table->string('dispatch_state', 30);
            /** 0 = inaugural / ainda não debitado; 1 = unidade comercial consumida. */
            $table->unsignedTinyInteger('quota_units')->default(0);
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            /** Chave estável do período (ex.: Y-m-d do period_starts_at). */
            $table->string('period_key', 40);
            $table->string('idempotency_key', 120);
            $table->string('technical_correlation_id', 64)->nullable();
            $table->unsignedBigInteger('technical_usage_entry_id')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('blocked_reason', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'mcle_idempotency_uq');
            $table->index(
                ['office_id', 'client_id', 'monitor_key', 'period_key'],
                'mcle_office_client_monitor_period_idx'
            );
            $table->index(['office_id', 'origin', 'dispatch_state'], 'mcle_office_origin_state_idx');
            $table->index(['office_id', 'period_key'], 'mcle_office_period_idx');
            $table->index('technical_correlation_id', 'mcle_tech_corr_idx');
        });

        // Partial uniques (SQLite + Postgres): inaugural e scheduled.
        DB::statement(
            "CREATE UNIQUE INDEX mcle_inaugural_unique
             ON monitor_commercial_ledger_entries (office_id, client_id, monitor_key)
             WHERE origin = 'inaugural'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mcle_scheduled_period_unique
             ON monitor_commercial_ledger_entries (office_id, client_id, monitor_key, period_key)
             WHERE origin = 'scheduled'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_commercial_ledger_entries');
    }
};
