<?php

namespace Tests\Unit\Integra;

use App\Services\Integra\ProxyPowerMatrixService;
use Tests\TestCase;

class ProcuracaoOfficialContractTest extends TestCase
{
    public function test_obter_procuracao_requires_all_four_identities(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(resource_path('serpro/official-service-catalog.v2026-07-16.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $operation = collect($catalog['entries'] ?? [])->firstWhere('operation_key', 'procuracoes.obter');
        $fields = collect($operation['request_schema']['fields'] ?? [])->keyBy('field');

        foreach (['outorgante', 'tipoOutorgante', 'outorgado', 'tipoOutorgado'] as $field) {
            $this->assertTrue((bool) ($fields[$field]['required'] ?? false), "{$field} precisa ser obrigatório");
        }
    }

    public function test_complete_matrix_resolves_multiple_official_ecac_services(): void
    {
        $matrix = app(ProxyPowerMatrixService::class);

        $this->assertSame(['00103'], array_column(
            $matrix->grantsForEcacSystemName('Acessar o sistema DCTFWeb'),
            'power_code',
        ));
        $this->assertSame(['00076'], array_column(
            $matrix->grantsForEcacSystemName('00076 - Parcelamento de Débitos do Simples Nacional'),
            'power_code',
        ));
        $this->assertSame([], $matrix->grantsForEcacSystemName('SISTEMA DESCONHECIDO'));
        $this->assertGreaterThan(10, count($matrix->hubTodosPowerGrants()));
    }
}
