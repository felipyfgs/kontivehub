<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgdasd_operations', function (Blueprint $table): void {
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_payment_status')) {
                $table->string('pagtoweb_payment_status', 16)->nullable()->after('payment_observed_at');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_verified_at')) {
                $table->timestampTz('pagtoweb_verified_at')->nullable()->after('pagtoweb_payment_status');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_paid_at')) {
                $table->date('pagtoweb_paid_at')->nullable()->after('pagtoweb_verified_at');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_amount_cents')) {
                $table->unsignedBigInteger('pagtoweb_amount_cents')->nullable()->after('pagtoweb_paid_at');
            }
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_source_run_id')) {
                $table->foreignId('pagtoweb_source_run_id')
                    ->nullable()
                    ->after('pagtoweb_amount_cents')
                    ->constrained('fiscal_monitoring_runs')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('pgdasd_operations', 'pagtoweb_source_item_id')) {
                $table->foreignId('pagtoweb_source_item_id')
                    ->nullable()
                    ->after('pagtoweb_source_run_id')
                    ->constrained('pagtoweb_payment_list_items')
                    ->nullOnDelete();
            }
        });

        Schema::table('pgdasd_operations', function (Blueprint $table): void {
            $table->index(
                ['office_id', 'client_id', 'pagtoweb_payment_status', 'pagtoweb_verified_at'],
                'pgo_office_client_pagtoweb_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pgdasd_operations', function (Blueprint $table): void {
            $table->dropIndex('pgo_office_client_pagtoweb_status_idx');

            foreach (['pagtoweb_source_item_id', 'pagtoweb_source_run_id'] as $column) {
                if (Schema::hasColumn('pgdasd_operations', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach ([
                'pagtoweb_amount_cents',
                'pagtoweb_paid_at',
                'pagtoweb_verified_at',
                'pagtoweb_payment_status',
            ] as $column) {
                if (Schema::hasColumn('pgdasd_operations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
