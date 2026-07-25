<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garante request_tag/functional_route/is_simulated em ambientes que já aplicaram
 * 2026_07_16_100000 com o guard antigo (só operation_key).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serpro_api_usage_entries')) {
            Schema::table('serpro_api_usage_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_api_usage_entries', 'request_tag')) {
                    $table->string('request_tag', 32)->nullable()->after('correlation_id');
                    $table->index('request_tag', 'serpro_usage_request_tag_idx');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'functional_route')) {
                    $table->string('functional_route', 20)->nullable()->after('request_tag');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'is_simulated')) {
                    $table->boolean('is_simulated')->default(false)->after('functional_route');
                }
            });
        }

        if (Schema::hasTable('serpro_api_usage_reservations')) {
            Schema::table('serpro_api_usage_reservations', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'request_tag')) {
                    $table->string('request_tag', 32)->nullable()->after('correlation_id');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'functional_route')) {
                    $table->string('functional_route', 20)->nullable()->after('request_tag');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'is_simulated')) {
                    $table->boolean('is_simulated')->default(false)->after('shadow_mode');
                }
            });
        }
    }

    public function down(): void
    {
        // Não remove colunas — podem ter sido criadas pela migration 100000.
    }
};
