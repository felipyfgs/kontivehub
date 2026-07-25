<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bootstrap idempotente da coordenada oficial DEFIS/CONSDECLARACAO142. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }
        $now = now();
        $catalogVersion = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);
        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $environment) {
            $entry = DB::table('serpro_service_catalog_entries')
                ->where('catalog_version', $catalogVersion)
                ->where('environment', $environment)
                ->where('solution_code', 'INTEGRA_SN')
                ->where('service_code', 'DEFIS')
                ->where('operation_code', 'CONSULTAR');
            $payload = [
                'operation_key' => 'defis.consdeclaracao',
                'id_sistema' => 'DEFIS',
                'id_servico' => 'CONSDECLARACAO142',
                'versao_sistema' => '1.0',
                'functional_route' => 'Consultar',
                'official_state' => 'PRODUCTION',
                'platform_support' => 'IMPLEMENTED',
                'dados_mode' => 'JSON_STRING',
                'label' => 'Consultar declarações DEFIS transmitidas',
                'is_mutating' => false,
                'is_enabled' => true,
                'required_proxy_power' => '00146',
                'billable_class' => 'CONSULTA',
                'cache_ttl_seconds' => 3600,
                'rate_limit_per_minute' => null,
                'coverage' => 'KNOWN',
                'metadata' => json_encode([
                    'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING',
                    'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING',
                    'required_proxy_powers' => ['00146'],
                    'async_policy' => 'HTTP_STATUS',
                    'monitoring_module' => 'simples_mei',
                    'billable_http_statuses' => [200, 202, 403],
                ], JSON_THROW_ON_ERROR),
                'effective_from' => $now,
                'effective_to' => null,
                'updated_at' => $now,
            ];
            if ($entry->exists()) {
                $entry->update($payload);
            } else {
                DB::table('serpro_service_catalog_entries')->insert($payload + [
                    'catalog_version' => $catalogVersion,
                    'environment' => $environment,
                    'solution_code' => 'INTEGRA_SN',
                    'service_code' => 'DEFIS',
                    'operation_code' => 'CONSULTAR',
                    'created_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('serpro_operation_catalog')
            && ! DB::table('serpro_operation_catalog')->where('operation_key', 'defis.consdeclaracao')->whereNull('effective_to')->exists()) {
            DB::table('serpro_operation_catalog')->insert([
                'operation_key' => 'defis.consdeclaracao',
                'system_code' => 'DEFIS',
                'service_code' => 'CONSDECLARACAO142',
                'operation_code' => 'defis.consdeclaracao',
                'consumption_class' => 'CONSULTA',
                'is_essential' => true,
                'effective_from' => $now,
                'effective_to' => null,
                'label' => 'Consultar declarações DEFIS',
                'notes' => 'Coordenada oficial DEFIS/CONSDECLARACAO142.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')
                ->where('operation_key', 'defis.consdeclaracao')
                ->where('id_sistema', 'DEFIS')
                ->where('id_servico', 'CONSDECLARACAO142')
                ->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')
                ->where('operation_key', 'defis.consdeclaracao')
                ->where('system_code', 'DEFIS')
                ->where('service_code', 'CONSDECLARACAO142')
                ->delete();
        }
    }
};
