<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico leve de transições de cursores multi-canal (CT-e DistDFe etc.).
 * Apenas metadados sanitizados — sem XML, PFX, vault ou chave completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_cursor_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_sync_cursor_id')->constrained('channel_sync_cursors')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('event', 60);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedBigInteger('from_last_nsu')->nullable();
            $table->unsignedBigInteger('to_last_nsu')->nullable();
            $table->string('last_cstat', 10)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(
                ['office_id', 'channel_sync_cursor_id', 'occurred_at'],
                'csc_transitions_office_cursor_at'
            );
            $table->index(['office_id', 'event'], 'csc_transitions_office_event');
            $table->index(['correlation_id'], 'csc_transitions_correlation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_cursor_transitions');
    }
};
