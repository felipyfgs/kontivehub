<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda de recorrência na Rotina (ProcessTemplate).
 * Aditivo: defaults fail-closed (recurrence_enabled=false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table): void {
            $table->boolean('recurrence_enabled')->default(false)->after('is_active');
            $table->string('recurrence_frequency', 16)->nullable()->after('recurrence_enabled');
            $table->unsignedTinyInteger('generation_day')->default(1)->after('recurrence_frequency');
            $table->unsignedTinyInteger('anchor_month')->nullable()->after('generation_day');
            $table->string('period_offset', 16)->default('PREVIOUS')->after('anchor_month');
            $table->timestampTz('next_run_at')->nullable()->after('period_offset');
            $table->foreignId('recurrence_owner_membership_id')
                ->nullable()
                ->after('next_run_at')
                ->constrained('office_user')
                ->nullOnDelete();

            $table->index(
                ['recurrence_enabled', 'is_active', 'next_run_at'],
                'pt_recurrence_due_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('process_templates', function (Blueprint $table): void {
            $table->dropIndex('pt_recurrence_due_idx');
            $table->dropConstrainedForeignId('recurrence_owner_membership_id');
            $table->dropColumn([
                'recurrence_enabled',
                'recurrence_frequency',
                'generation_day',
                'anchor_month',
                'period_offset',
                'next_run_at',
            ]);
        });
    }
};
