<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo global versionado de soluções/serviços Integra Contador.
 * SEM office_id. Seed inicial com entradas conhecidas (mutabilidade, poder, cache, classe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_service_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('catalog_version');
            $table->string('environment', 20);
            $table->string('solution_code', 80);
            $table->string('service_code', 120);
            $table->string('operation_code', 120);
            $table->string('label');
            $table->boolean('is_mutating')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('required_proxy_power', 120)->nullable();
            $table->string('billable_class', 40);
            $table->unsignedInteger('cache_ttl_seconds')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->string('coverage', 40)->default('KNOWN');
            $table->json('metadata')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->unique(
                ['catalog_version', 'environment', 'solution_code', 'service_code', 'operation_code'],
                'serpro_catalog_unique_op',
            );
            $table->index(['solution_code', 'service_code']);
            $table->index(['environment', 'is_enabled']);
        });

        $now = now();
        $version = 1;
        $rows = $this->seedRows($version, $now);

        foreach ($rows as $row) {
            DB::table('serpro_service_catalog_entries')->insert($row);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_service_catalog_entries');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seedRows(int $version, $now): array
    {
        $base = [
            'catalog_version' => $version,
            'environment' => 'TRIAL',
            'is_enabled' => true,
            'cache_ttl_seconds' => 3600,
            'rate_limit_per_minute' => 30,
            'coverage' => 'KNOWN',
            'metadata' => null,
            'effective_from' => $now,
            'effective_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $ops = [
            // solution, service, operation, label, mutating, power, billable, cache
            ['INTEGRA_PROCURACOES', 'PROCURACOES', 'CONSULTAR', 'Consultar procurações', false, 'PROCURACOES', 'CONSULTA', 1800],
            ['AUTENTICAPROCURADOR', 'AUTH', 'AUTENTICAR', 'Autenticar procurador', false, null, 'NAO_FATURAVEL', 0],
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_DECLARACAO', 'Consultar declaração PGDAS-D', false, 'PGDASD', 'CONSULTA', 3600],
            ['INTEGRA_SN', 'DEFIS', 'CONSULTAR', 'Consultar DEFIS', false, 'DEFIS', 'CONSULTA', 3600],
            ['INTEGRA_MEI', 'PGMEI', 'CONSULTAR', 'Consultar PGMEI', false, 'PGMEI', 'CONSULTA', 3600],
            ['INTEGRA_MEI', 'CCMEI', 'CONSULTAR', 'Consultar CCMEI', false, 'CCMEI', 'CONSULTA', 3600],
            ['INTEGRA_DCTFWEB', 'DCTFWEB', 'CONSULTAR_RECIBO', 'Consultar recibo DCTFWeb', false, 'DCTFWEB', 'CONSULTA', 1800],
            ['INTEGRA_MIT', 'MIT', 'CONSULTAR_SITUACAO', 'Consultar situação MIT', false, 'MIT', 'CONSULTA', 1800],
            ['INTEGRA_PARCELAMENTO', 'PARCELAMENTO', 'CONSULTAR_PEDIDO', 'Consultar pedido de parcelamento', false, 'PARCELAMENTO', 'CONSULTA', 1800],
            ['INTEGRA_SITFIS', 'SITFIS', 'SOLICITAR_RELATORIO', 'Solicitar relatório SITFIS', false, 'SITFIS', 'CONSULTA', 86400],
            ['INTEGRA_CAIXAPOSTAL', 'CAIXA_POSTAL', 'LISTAR', 'Listar mensagens Caixa Postal', false, 'CAIXA_POSTAL', 'CONSULTA', 900],
            ['INTEGRA_PAGAMENTO', 'SICALC', 'CONSULTAR', 'Consultar Sicalc', false, 'SICALC', 'CONSULTA', 1800],
            // Mutantes desabilitados no catálogo seed (piloto somente leitura)
            ['INTEGRA_SN', 'PGDASD', 'TRANSMITIR', 'Transmitir PGDAS-D', true, 'PGDASD', 'DECLARACAO', 0],
            ['INTEGRA_PAGAMENTO', 'SICALC', 'EMITIR_GUIA', 'Emitir guia', true, 'SICALC', 'EMISSAO', 0],
        ];

        $rows = [];
        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $env) {
            foreach ($ops as [$solution, $service, $operation, $label, $mutating, $power, $billable, $cache]) {
                $rows[] = array_merge($base, [
                    'environment' => $env,
                    'solution_code' => $solution,
                    'service_code' => $service,
                    'operation_code' => $operation,
                    'label' => $label,
                    'is_mutating' => $mutating,
                    // Mutantes começam desabilitados no catálogo
                    'is_enabled' => ! $mutating,
                    'required_proxy_power' => $power,
                    'billable_class' => $billable,
                    'cache_ttl_seconds' => $cache,
                    'rate_limit_per_minute' => $mutating ? 10 : 30,
                ]);
            }
        }

        return $rows;
    }
};
