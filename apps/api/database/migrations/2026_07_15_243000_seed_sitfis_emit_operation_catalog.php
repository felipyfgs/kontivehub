<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completa catálogo SITFIS com EMITIR_RELATORIO e MONITOR (fluxo assíncrono).
 * SOLICITAR_RELATORIO já existe no seed 210100.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }

        $now = now();
        $version = (int) (DB::table('serpro_service_catalog_entries')->max('catalog_version') ?: 1);

        $ops = [
            // solution, service, operation, label, mutating, power, billable, cache
            ['INTEGRA_SITFIS', 'SITFIS', 'EMITIR_RELATORIO', 'Emitir relatório SITFIS por protocolo', false, 'SITFIS', 'CONSULTA', 86400],
            ['INTEGRA_SITFIS', 'SITFIS', 'MONITOR', 'Monitoramento SITFIS (orquestração interna)', false, 'SITFIS', 'CONSULTA', 86400],
        ];

        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $env) {
            foreach ($ops as [$solution, $service, $operation, $label, $mutating, $power, $billable, $cache]) {
                $exists = DB::table('serpro_service_catalog_entries')
                    ->where('catalog_version', $version)
                    ->where('environment', $env)
                    ->where('solution_code', $solution)
                    ->where('service_code', $service)
                    ->where('operation_code', $operation)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('serpro_service_catalog_entries')->insert([
                    'catalog_version' => $version,
                    'environment' => $env,
                    'solution_code' => $solution,
                    'service_code' => $service,
                    'operation_code' => $operation,
                    'label' => $label,
                    'is_mutating' => $mutating,
                    'is_enabled' => ! $mutating,
                    'required_proxy_power' => $power,
                    'billable_class' => $billable,
                    'cache_ttl_seconds' => $cache,
                    'rate_limit_per_minute' => 30,
                    'coverage' => 'KNOWN',
                    'metadata' => json_encode(['module' => 'sitfis', 'async' => true], JSON_THROW_ON_ERROR),
                    'effective_from' => $now,
                    'effective_to' => null,
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
            ->where('solution_code', 'INTEGRA_SITFIS')
            ->where('service_code', 'SITFIS')
            ->whereIn('operation_code', ['EMITIR_RELATORIO', 'MONITOR'])
            ->delete();
    }
};
