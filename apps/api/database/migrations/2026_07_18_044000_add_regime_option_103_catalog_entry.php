<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bootstrap idempotente da coordenada oficial REGIMEAPURACAO/CONSULTAROPCAOREGIME103. */
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
                ->where('service_code', 'REGIME_APURACAO')
                ->where('operation_code', 'CONSULTAR');
            $payload = [
                'operation_key' => 'regimeapuracao.consultaropcaoregime',
                'id_sistema' => 'REGIMEAPURACAO',
                'id_servico' => 'CONSULTAROPCAOREGIME103',
                'versao_sistema' => '1.0',
                'functional_route' => 'Consultar',
                'official_state' => 'PRODUCTION',
                'platform_support' => 'IMPLEMENTED',
                'dados_mode' => 'JSON_STRING',
                'label' => 'Consultar opção de Regime de Apuração de Receitas',
                'is_mutating' => false,
                'is_enabled' => true,
                'required_proxy_power' => '00060',
                'billable_class' => 'CONSULTA',
                'cache_ttl_seconds' => 3600,
                'rate_limit_per_minute' => null,
                'coverage' => 'KNOWN',
                'metadata' => json_encode([
                    'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING',
                    'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING',
                    'required_proxy_powers' => ['00060'],
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

                continue;
            }

            DB::table('serpro_service_catalog_entries')->insert($payload + [
                'catalog_version' => $catalogVersion,
                'environment' => $environment,
                'solution_code' => 'INTEGRA_SN',
                'service_code' => 'REGIME_APURACAO',
                'operation_code' => 'CONSULTAR',
                'created_at' => $now,
            ]);
        }

        if (Schema::hasTable('serpro_operation_catalog')
            && ! DB::table('serpro_operation_catalog')
                ->where('operation_key', 'regimeapuracao.consultaropcaoregime')
                ->whereNull('effective_to')
                ->exists()) {
            DB::table('serpro_operation_catalog')->insert([
                'operation_key' => 'regimeapuracao.consultaropcaoregime',
                'system_code' => 'REGIMEAPURACAO',
                'service_code' => 'CONSULTAROPCAOREGIME103',
                'operation_code' => 'regimeapuracao.consultaropcaoregime',
                'consumption_class' => 'CONSULTA',
                'is_essential' => true,
                'effective_from' => $now,
                'effective_to' => null,
                'label' => 'Consultar opção de Regime de Apuração',
                'notes' => 'Coordenada oficial REGIMEAPURACAO/CONSULTAROPCAOREGIME103.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')
                ->where('operation_key', 'regimeapuracao.consultaropcaoregime')
                ->where('id_sistema', 'REGIMEAPURACAO')
                ->where('id_servico', 'CONSULTAROPCAOREGIME103')
                ->delete();
        }
        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')
                ->where('operation_key', 'regimeapuracao.consultaropcaoregime')
                ->where('system_code', 'REGIMEAPURACAO')
                ->where('service_code', 'CONSULTAROPCAOREGIME103')
                ->delete();
        }
    }
};
