<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('process_templates', function (Blueprint $table): void {
            $table->string('catalog_key', 80)->nullable()->after('office_id');
            $table->unsignedSmallInteger('catalog_version')->nullable()->after('catalog_key');
            $table->string('monitoring_module_key', 40)->nullable()->after('description');
            $table->json('audience_rules')->nullable()->after('monitoring_module_key');

            $table->unique(['office_id', 'catalog_key'], 'process_templates_office_catalog_uq');
            $table->index(['office_id', 'monitoring_module_key'], 'process_templates_office_monitor_idx');
        });

        Schema::table('operational_processes', function (Blueprint $table): void {
            $table->string('monitoring_module_key', 40)->nullable()->after('description');
            $table->index(
                ['office_id', 'monitoring_module_key', 'status'],
                'operational_processes_monitor_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('operational_processes', function (Blueprint $table): void {
            $table->dropIndex('operational_processes_monitor_status_idx');
            $table->dropColumn('monitoring_module_key');
        });

        Schema::table('process_templates', function (Blueprint $table): void {
            $table->dropUnique('process_templates_office_catalog_uq');
            $table->dropIndex('process_templates_office_monitor_idx');
            $table->dropColumn([
                'catalog_key',
                'catalog_version',
                'monitoring_module_key',
                'audience_rules',
            ]);
        });
    }
};
