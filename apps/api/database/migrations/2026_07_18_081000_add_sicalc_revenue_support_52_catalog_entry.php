<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Coordenada oficial SICALC / CONSULTAAPOIORECEITAS52, rota Apoiar. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);
            foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $environment) {
                DB::table('serpro_service_catalog_entries')->updateOrInsert([
                    'catalog_version' => $version, 'environment' => $environment, 'solution_code' => 'SICALC',
                    'service_code' => 'SICALC', 'operation_code' => 'CONSULTAR_APOIO_RECEITAS',
                ], [
                    'operation_key' => 'sicalc.consultaapoioreceitas', 'id_sistema' => 'SICALC',
                    'id_servico' => 'CONSULTAAPOIORECEITAS52', 'versao_sistema' => '2.9',
                    'functional_route' => 'Apoiar', 'official_state' => 'PRODUCTION', 'platform_support' => 'IMPLEMENTED',
                    'dados_mode' => 'JSON_STRING', 'label' => 'Apoio de consulta Receitas do Sicalc',
                    'is_mutating' => false, 'is_enabled' => true, 'required_proxy_power' => null,
                    'billable_class' => 'NAO_FATURAVEL', 'cache_ttl_seconds' => 3600, 'rate_limit_per_minute' => null,
                    'coverage' => 'KNOWN',
                    'metadata' => json_encode([
                        'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING', 'proxy_rule' => 'NOT_APPLICABLE',
                        'required_proxy_powers' => [], 'async_policy' => 'HTTP_STATUS', 'monitoring_module' => 'guides',
                        'billable_http_statuses' => [],
                    ], JSON_THROW_ON_ERROR),
                    'effective_from' => $now, 'effective_to' => null, 'updated_at' => $now, 'created_at' => $now,
                ]);
            }
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->updateOrInsert([
                'operation_key' => 'sicalc.consultaapoioreceitas', 'system_code' => 'SICALC',
                'service_code' => 'CONSULTAAPOIORECEITAS52',
            ], [
                'operation_code' => 'sicalc.consultaapoioreceitas', 'consumption_class' => 'NAO_FATURAVEL',
                'is_essential' => false, 'effective_from' => $now, 'effective_to' => null,
                'label' => 'Apoio de consulta Receitas do Sicalc',
                'notes' => 'Coordenada oficial SICALC/CONSULTAAPOIORECEITAS52; rota Apoiar não faturável.',
                'updated_at' => $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')->where('operation_key', 'sicalc.consultaapoioreceitas')->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')->where('operation_key', 'sicalc.consultaapoioreceitas')->delete();
        }
    }
};
