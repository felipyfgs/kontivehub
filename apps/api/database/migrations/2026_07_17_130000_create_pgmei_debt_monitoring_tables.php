<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeção anual + observações imutáveis + itens de dívida ativa PGMEI (DIVIDAATIVA24).
 * Migrations apenas aditivas — não remove superfícies/dados legados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pgmei_debt_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('calendar_year');
            $table->string('debt_state', 32);
            $table->string('digest', 64);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestampTz('observed_at');
            $table->foreignId('source_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->foreignId('source_snapshot_id')
                ->nullable()
                ->constrained('fiscal_snapshots')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['office_id', 'source_run_id'],
                'pgmei_obs_office_run_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'calendar_year', 'observed_at'],
                'pgmei_obs_office_client_year_observed_idx'
            );
            $table->index(
                ['office_id', 'client_id', 'calendar_year', 'digest'],
                'pgmei_obs_office_client_year_digest_idx'
            );
        });

        Schema::create('pgmei_debt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('observation_id')
                ->constrained('pgmei_debt_observations')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('logical_key', 64);
            $table->string('periodo_apuracao', 6);
            $table->string('tributo', 120);
            $table->unsignedBigInteger('amount_cents');
            $table->string('ente_federado', 120);
            $table->string('situacao_debito', 255);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['office_id', 'client_id', 'observation_id'],
                'pgmei_item_office_client_obs_idx'
            );
            $table->unique(
                ['observation_id', 'logical_key'],
                'pgmei_item_obs_logical_uq'
            );
        });

        Schema::create('pgmei_debt_projections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('calendar_year');
            $table->string('debt_state', 32)->default('UNVERIFIED');
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestampTz('last_valid_query_at')->nullable();
            $table->foreignId('last_valid_observation_id')
                ->nullable()
                ->constrained('pgmei_debt_observations')
                ->nullOnDelete();
            $table->foreignId('last_valid_run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->foreignId('last_valid_snapshot_id')
                ->nullable()
                ->constrained('fiscal_snapshots')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'calendar_year'],
                'pgmei_proj_office_client_year_uq'
            );
            $table->index(
                ['office_id', 'debt_state', 'last_valid_query_at'],
                'pgmei_proj_office_state_query_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pgmei_debt_projections');
        Schema::dropIfExists('pgmei_debt_items');
        Schema::dropIfExists('pgmei_debt_observations');
    }
};
