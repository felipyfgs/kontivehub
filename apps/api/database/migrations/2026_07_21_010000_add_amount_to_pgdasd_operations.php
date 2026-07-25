<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgdasd_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('pgdasd_operations', 'amount_cents')) {
                $table->unsignedBigInteger('amount_cents')->nullable()->after('payment_observed_at');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'amount_source')) {
                $table->string('amount_source', 32)->nullable()->after('amount_cents');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'amount_parser_version')) {
                $table->string('amount_parser_version', 64)->nullable()->after('amount_source');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'amount_resolved_at')) {
                $table->timestampTz('amount_resolved_at')->nullable()->after('amount_parser_version');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'amount_source_artifact_id')) {
                $table->foreignId('amount_source_artifact_id')
                    ->nullable()
                    ->after('amount_resolved_at')
                    ->constrained('pgdasd_artifacts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pgdasd_operations', function (Blueprint $table): void {
            if (Schema::hasColumn('pgdasd_operations', 'amount_source_artifact_id')) {
                $table->dropConstrainedForeignId('amount_source_artifact_id');
            }
            foreach (['amount_resolved_at', 'amount_parser_version', 'amount_source', 'amount_cents'] as $column) {
                if (Schema::hasColumn('pgdasd_operations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
