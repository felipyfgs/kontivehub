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
                    'catalog_version' => $version, 'environment' => $environment, 'solution_code' => 'PAGTOWEB', 'service_code' => 'PAGTOWEB', 'operation_code' => 'CONSULTAR_PAGAMENTOS',
                ], [
                    'operation_key' => 'pagtoweb.pagamentos', 'id_sistema' => 'PAGTOWEB', 'id_servico' => 'PAGAMENTOS71', 'versao_sistema' => '1.0', 'functional_route' => 'Consultar',
                    'official_state' => 'PRODUCTION', 'platform_support' => 'IMPLEMENTED', 'dados_mode' => 'JSON_STRING', 'label' => 'Consulta Pagamentos', 'is_mutating' => false, 'is_enabled' => true,
                    'required_proxy_power' => '00004', 'billable_class' => 'CONSULTA', 'cache_ttl_seconds' => 300, 'rate_limit_per_minute' => null, 'coverage' => 'KNOWN',
                    'metadata' => json_encode(['auth_mode' => 'PROCURATOR_WHEN_REPRESENTING', 'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING', 'required_proxy_powers' => ['00004'], 'async_policy' => 'HTTP_STATUS', 'monitoring_module' => 'guides', 'billable_http_statuses' => [200, 202, 403]], JSON_THROW_ON_ERROR),
                    'effective_from' => $now, 'effective_to' => null, 'updated_at' => $now, 'created_at' => $now,
                ]);
            }
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'operation_key' => 'pagtoweb.pagamentos', 'system_code' => 'PAGTOWEB', 'service_code' => 'PAGAMENTOS71',
            ], [
                'operation_code' => 'pagtoweb.pagamentos', 'consumption_class' => 'CONSULTA', 'is_essential' => false, 'effective_from' => $now, 'effective_to' => null,
                'label' => 'Consulta Pagamentos', 'notes' => 'Coordenada oficial PAGTOWEB/PAGAMENTOS71; leitura potencialmente faturável com itens sanitizados.', 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')->where('operation_key', 'pagtoweb.pagamentos')->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->where('operation_key', 'pagtoweb.pagamentos')->delete();
        }
    }
};
