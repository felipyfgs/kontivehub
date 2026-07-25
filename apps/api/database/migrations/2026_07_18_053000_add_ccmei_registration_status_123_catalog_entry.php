<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Coordenada oficial CCMEI / CCMEISITCADASTRAL123. */
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
                'catalog_version' => $version, 'environment' => $environment, 'solution_code' => 'INTEGRA_MEI',
                'service_code' => 'CCMEI', 'operation_code' => 'CONSULTAR_SITUACAO_CADASTRAL',
            ], [
                'operation_key' => 'ccmei.ccmeisitcadastral', 'id_sistema' => 'CCMEI', 'id_servico' => 'CCMEISITCADASTRAL123',
                'versao_sistema' => '1.0', 'functional_route' => 'Consultar', 'official_state' => 'PRODUCTION',
                'platform_support' => 'IMPLEMENTED', 'dados_mode' => 'JSON_STRING', 'label' => 'Consultar situação cadastral CCMEI',
                'is_mutating' => false, 'is_enabled' => true, 'required_proxy_power' => null, 'billable_class' => 'CONSULTA',
                'cache_ttl_seconds' => 3600, 'rate_limit_per_minute' => null, 'coverage' => 'KNOWN',
                'metadata' => json_encode([
                    'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING', 'proxy_rule' => 'NOT_APPLICABLE',
                    'required_proxy_powers' => [], 'async_policy' => 'HTTP_STATUS', 'monitoring_module' => 'simples_mei',
                    'billable_http_statuses' => [200, 202, 403],
                ], JSON_THROW_ON_ERROR),
                'effective_from' => $now, 'effective_to' => null, 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'operation_key' => 'ccmei.ccmeisitcadastral', 'system_code' => 'CCMEI', 'service_code' => 'CCMEISITCADASTRAL123',
            ], [
                'operation_code' => 'ccmei.ccmeisitcadastral', 'consumption_class' => 'CONSULTA', 'is_essential' => false,
                'effective_from' => $now, 'effective_to' => null, 'label' => 'Consultar situação cadastral CCMEI',
                'notes' => 'Coordenada oficial CCMEI/CCMEISITCADASTRAL123.', 'updated_at' => $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')->where('operation_key', 'ccmei.ccmeisitcadastral')->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->where('operation_key', 'ccmei.ccmeisitcadastral')->delete();
        }
    }
};
