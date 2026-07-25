<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado operacional PGDAS-D e referências de última declaração/RBT12 na projeção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_obligation_projections', function (Blueprint $table): void {
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_declaration_state')) {
                $table->string('pgdasd_declaration_state', 40)->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_last_productive_consulted_at')) {
                $table->timestampTz('pgdasd_last_productive_consulted_at')->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_last_declaration_operation_id')) {
                $table->foreignId('pgdasd_last_declaration_operation_id')
                    ->nullable()
                    ->constrained('pgdasd_operations')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_latest_rbt12_projection_id')) {
                $table->foreignId('pgdasd_latest_rbt12_projection_id')
                    ->nullable()
                    ->constrained('pgdasd_rbt12_projections')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_calendar_version_code')) {
                $table->string('pgdasd_calendar_version_code', 60)->nullable();
            }
            if (! Schema::hasColumn('tax_obligation_projections', 'pgdasd_calendar_verified')) {
                $table->boolean('pgdasd_calendar_verified')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tax_obligation_projections', function (Blueprint $table): void {
            if (Schema::hasColumn('tax_obligation_projections', 'pgdasd_latest_rbt12_projection_id')) {
                $table->dropConstrainedForeignId('pgdasd_latest_rbt12_projection_id');
            }
            if (Schema::hasColumn('tax_obligation_projections', 'pgdasd_last_declaration_operation_id')) {
                $table->dropConstrainedForeignId('pgdasd_last_declaration_operation_id');
            }
            $cols = array_filter([
                Schema::hasColumn('tax_obligation_projections', 'pgdasd_declaration_state') ? 'pgdasd_declaration_state' : null,
                Schema::hasColumn('tax_obligation_projections', 'pgdasd_last_productive_consulted_at') ? 'pgdasd_last_productive_consulted_at' : null,
                Schema::hasColumn('tax_obligation_projections', 'pgdasd_calendar_version_code') ? 'pgdasd_calendar_version_code' : null,
                Schema::hasColumn('tax_obligation_projections', 'pgdasd_calendar_verified') ? 'pgdasd_calendar_verified' : null,
            ]);
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
