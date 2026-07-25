<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Coordenada oficial bootstrap de MIT/LISTAAPURACOES317 para bancos já migrados. */
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
            $exists = DB::table('serpro_service_catalog_entries')
                ->where('catalog_version', $catalogVersion)
                ->where('environment', $environment)
                ->where('solution_code', 'INTEGRA_MIT')
                ->where('service_code', 'MIT')
                ->where('operation_code', 'LISTAR_APURACOES')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('serpro_service_catalog_entries')->insert([
                'catalog_version' => $catalogVersion,
                'environment' => $environment,
                'operation_key' => 'mit.listaapuracoes',
                'solution_code' => 'INTEGRA_MIT',
                'service_code' => 'MIT',
                'operation_code' => 'LISTAR_APURACOES',
                'id_sistema' => 'MIT',
                'id_servico' => 'LISTAAPURACOES317',
                'versao_sistema' => '1.0',
                'functional_route' => 'Consultar',
                'official_state' => 'PRODUCTION',
                'platform_support' => 'IMPLEMENTED',
                'dados_mode' => 'JSON_STRING',
                'label' => 'Consultar Apurações MIT por ano ou mês',
                'is_mutating' => false,
                'is_enabled' => true,
                'required_proxy_power' => '00103',
                'billable_class' => 'CONSULTA',
                'cache_ttl_seconds' => 3600,
                'rate_limit_per_minute' => null,
                'coverage' => 'KNOWN',
                'metadata' => json_encode([
                    'auth_mode' => 'PROCURATOR_WHEN_REPRESENTING',
                    'proxy_rule' => 'REQUIRED_WHEN_REPRESENTING',
                    'required_proxy_powers' => ['00103'],
                    'async_policy' => 'HTTP_STATUS',
                    'monitoring_module' => 'dctfweb',
                ], JSON_THROW_ON_ERROR),
                'effective_from' => $now,
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // O ledger de uso classifica pelas coordenadas resolvidas (MIT/317),
        // não pelo alias legado. Sem este registro a consulta seria marcada
        // DESCONHECIDA, mesmo sendo oficialmente uma CONSULTA faturável.
        if (Schema::hasTable('serpro_operation_catalog')) {
            $exists = DB::table('serpro_operation_catalog')
                ->where('system_code', 'MIT')
                ->where('service_code', 'LISTAAPURACOES317')
                ->where('operation_code', 'mit.listaapuracoes')
                ->whereNull('effective_to')
                ->exists();

            if (! $exists) {
                DB::table('serpro_operation_catalog')->insert([
                    'operation_key' => 'mit.listaapuracoes',
                    'system_code' => 'MIT',
                    'service_code' => 'LISTAAPURACOES317',
                    'operation_code' => 'mit.listaapuracoes',
                    'consumption_class' => 'CONSULTA',
                    'is_essential' => true,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'label' => 'Consultar Apurações MIT por ano ou mês',
                    'notes' => 'Coordenada oficial MIT/LISTAAPURACOES317.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }

        DB::table('serpro_service_catalog_entries')
            ->where('operation_key', 'mit.listaapuracoes')
            ->where('id_sistema', 'MIT')
            ->where('id_servico', 'LISTAAPURACOES317')
            ->delete();

        if (Schema::hasTable('serpro_operation_catalog')) {
            DB::table('serpro_operation_catalog')
                ->where('operation_key', 'mit.listaapuracoes')
                ->where('system_code', 'MIT')
                ->where('service_code', 'LISTAAPURACOES317')
                ->where('operation_code', 'mit.listaapuracoes')
                ->delete();
        }
    }
};
