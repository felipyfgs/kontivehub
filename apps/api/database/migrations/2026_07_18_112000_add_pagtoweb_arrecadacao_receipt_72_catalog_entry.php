<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);
            foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $environment) {
                DB::table('serpro_service_catalog_entries')->updateOrInsert([
                    'catalog_version' => $version,
                    'environment' => $environment,
                    'solution_code' => 'PAGTOWEB',
                    'service_code' => 'COMPARRECADACAO72',
                    'operation_code' => 'EMITIR_COMPROVANTE_ARRECADACAO',
                ], [
                    'operation_key' => 'pagtoweb.comparrecadacao',
                    'id_sistema' => 'PAGTOWEB',
                    'id_servico' => 'COMPARRECADACAO72',
                    'versao_sistema' => '1.0',
                    'functional_route' => 'Emitir',
                    'official_state' => 'PRODUCTION',
                    'platform_support' => 'IMPLEMENTED',
                    'dados_mode' => 'JSON_STRING',
                    'label' => 'Emitir Comprovante de Arrecadação',
                    'is_mutating' => false,
                    'is_enabled' => true,
                    'required_proxy_power' => '00004',
                    'billable_class' => 'EMISSAO',
                    'cache_ttl_seconds' => 0,
                    'rate_limit_per_minute' => null,
                    'coverage' => 'KNOWN',
                    'metadata' => json_encode([
                        'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING',
                        'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING',
                        'required_proxy_powers' => ['00004'],
                        'async_policy' => 'HTTP_STATUS',
                        'monitoring_module' => 'guides',
                        'billable_http_statuses' => [200, 202, 403],
                    ], JSON_THROW_ON_ERROR),
                    'effective_from' => $now,
                    'effective_to' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]);
            }
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'operation_key' => 'pagtoweb.comparrecadacao',
                'system_code' => 'PAGTOWEB',
                'service_code' => 'COMPARRECADACAO72',
            ], [
                'operation_code' => 'pagtoweb.comparrecadacao',
                'consumption_class' => 'EMISSAO',
                'is_essential' => false,
                'effective_from' => $now,
                'effective_to' => null,
                'label' => 'Emitir Comprovante de Arrecadação',
                'notes' => 'Coordenada oficial PAGTOWEB/COMPARRECADACAO72; emissão bilhetável com PDF somente no cofre.',
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')->where('operation_key', 'pagtoweb.comparrecadacao')->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->where('operation_key', 'pagtoweb.comparrecadacao')->delete();
        }
    }
};
