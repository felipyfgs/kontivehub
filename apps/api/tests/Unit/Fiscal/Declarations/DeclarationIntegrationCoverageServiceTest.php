<?php

namespace Tests\Unit\Fiscal\Declarations;

use App\Services\Fiscal\Declarations\DeclarationIntegrationCoverageService;
use Tests\TestCase;

class DeclarationIntegrationCoverageServiceTest extends TestCase
{
    public function test_it_projects_all_declaration_obligations_fail_closed(): void
    {
        $coverage = app(DeclarationIntegrationCoverageService::class)->publicCoverage();
        $byCode = collect($coverage['obligations'])->keyBy('code');

        $this->assertSame(
            ['PGDAS', 'DEFIS', 'DASN_SIMEI', 'DCTFWEB', 'MIT', 'FGTS', 'DIRF'],
            $byCode->keys()->all(),
        );
        $this->assertTrue($byCode['PGDAS']['monitoring_supported']);
        $this->assertTrue($byCode['PGDAS']['transmission_supported']);
        $this->assertSame('INVENTORIED', $byCode['DASN_SIMEI']['coverage']);
        $this->assertFalse($byCode['DASN_SIMEI']['monitoring_supported']);
        $this->assertFalse($byCode['DASN_SIMEI']['transmission_supported']);
        $this->assertSame('PARTIAL', $byCode['DCTFWEB']['coverage']);
        $this->assertSame('PARTIAL', $byCode['FGTS']['coverage']);
        $this->assertSame('UNSUPPORTED', $byCode['DIRF']['coverage']);
        $this->assertSame(0, $byCode['FGTS']['operations_total']);
        $this->assertSame([], $byCode['DIRF']['routes']);
    }

    public function test_public_projection_does_not_expose_internal_coordinates_or_payloads(): void
    {
        $json = json_encode(
            app(DeclarationIntegrationCoverageService::class)->publicCoverage(),
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'operation_key',
            'id_sistema',
            'id_servico',
            'request_schema',
            'response_schema',
            'required_proxy_power',
            'dados_mode',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
