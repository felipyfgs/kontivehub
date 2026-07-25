<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }
        $now = now();
        $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);
        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $environment) {
            DB::table('serpro_service_catalog_entries')->updateOrInsert([
                'catalog_version' => $version, 'environment' => $environment, 'solution_code' => 'INTEGRA_SN',
                'service_code' => 'DEFIS', 'operation_code' => 'CONSULTAR_DECLARACAO_RECIBO',
            ], [
                'operation_key' => 'defis.consdecrec', 'id_sistema' => 'DEFIS', 'id_servico' => 'CONSDECREC144',
                'versao_sistema' => '1.0', 'functional_route' => 'Consultar', 'official_state' => 'PRODUCTION',
                'platform_support' => 'IMPLEMENTED', 'dados_mode' => 'JSON_STRING', 'label' => 'Consultar declaração DEFIS e recibo',
                'is_mutating' => false, 'is_enabled' => true, 'required_proxy_power' => '00146', 'billable_class' => 'CONSULTA',
                'cache_ttl_seconds' => 3600, 'rate_limit_per_minute' => null, 'coverage' => 'KNOWN',
                'metadata' => json_encode(['auth_mode' => 'PROCURATOR_WHEN_REPRESENTING', 'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING', 'required_proxy_powers' => ['00146'], 'async_policy' => 'HTTP_STATUS', 'monitoring_module' => 'simples_mei', 'billable_http_statuses' => [200, 202, 403]], JSON_THROW_ON_ERROR),
                'effective_from' => $now, 'effective_to' => null, 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'operation_key' => 'defis.consdecrec', 'system_code' => 'DEFIS', 'service_code' => 'CONSDECREC144',
            ], [
                'operation_code' => 'defis.consdecrec', 'consumption_class' => 'CONSULTA', 'is_essential' => true,
                'effective_from' => $now, 'effective_to' => null, 'label' => 'Consultar declaração DEFIS e recibo',
                'notes' => 'Coordenada oficial DEFIS/CONSDECREC144.', 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')->where('operation_key', 'defis.consdecrec')->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->where('operation_key', 'defis.consdecrec')->delete();
        }
    }
};
