<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harness da consolidação do modelo fiscal: mapa origem-destino e checkpoints.
 * Pré-condições explícitas (sem hasTable silencioso).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPrecondition::tablesExist(
            ['offices'],
            'fiscal_model_migration_harness',
        );
        MigrationPrecondition::tableMissing(
            'fiscal_model_migration_maps',
            'fiscal_model_migration_harness',
        );
        MigrationPrecondition::tableMissing(
            'fiscal_model_backfill_checkpoints',
            'fiscal_model_migration_harness',
        );

        Schema::create('fiscal_model_migration_maps', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate', 64);
            $table->string('source_table', 128);
            $table->string('source_id', 64);
            $table->string('target_table', 128)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->foreignId('office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->string('correlation_id', 120)->nullable();
            $table->string('status', 32); // MAPPED|AMBIGUOUS|REJECTED
            $table->string('notes_sanitized', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['aggregate', 'source_table', 'source_id'],
                'fiscal_model_maps_source_unique',
            );
            $table->index(['aggregate', 'status']);
            $table->index(['office_id', 'aggregate']);
        });

        Schema::create('fiscal_model_backfill_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate', 64);
            $table->string('cursor_key', 128);
            $table->string('cursor_value', 128);
            $table->foreignId('office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['aggregate', 'cursor_key'],
                'fiscal_model_checkpoints_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_model_backfill_checkpoints');
        Schema::dropIfExists('fiscal_model_migration_maps');
    }
};
