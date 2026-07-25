<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLATFORM_ADMIN em platform_privileged pode mutar Work sem dual membership.
 * Paridade com operational_processes.created_by_membership_id (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_membership_id');
        });

        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->foreignId('requested_by_membership_id')
                ->nullable()
                ->after('preview_summary')
                ->constrained('office_user')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_membership_id');
        });

        Schema::table('process_generation_batches', function (Blueprint $table): void {
            $table->foreignId('requested_by_membership_id')
                ->after('preview_summary')
                ->constrained('office_user')
                ->cascadeOnDelete();
        });
    }
};
