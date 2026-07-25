<?php

namespace Tests\Unit\Fiscal\Declarations;

use App\Services\Fiscal\Declarations\DeclarationMutationPayloadCodec;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DeclarationMutationPayloadCodecTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>  $expectedKeys
     */
    #[DataProvider('productionMutationCases')]
    public function test_it_encodes_each_production_mutation(
        string $actionId,
        array $params,
        array $expectedKeys,
    ): void {
        $payload = app(DeclarationMutationPayloadCodec::class)->encode(
            $actionId,
            $params,
            '11.222.333/0001-81',
        );

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $payload, "{$actionId} sem {$key}");
        }
        $this->assertArrayNotHasKey('idSistema', $payload);
        $this->assertArrayNotHasKey('idServico', $payload);
        $this->assertArrayNotHasKey('operation_key', $payload);
    }

    /** @return iterable<string, array{string, array<string, mixed>, list<string>}> */
    public static function productionMutationCases(): iterable
    {
        yield 'PGDAS-D entregar' => [
            'decl_pgdas_entregar',
            [
                'period_key' => '2026-06',
                'business_payload' => [
                    'indicadorTransmissao' => true,
                    'indicadorComparacao' => false,
                    'declaracao' => [],
                ],
            ],
            ['cnpjCompleto', 'pa', 'declaracao'],
        ];
        yield 'PGDAS-D gerar DAS' => [
            'decl_pgdas_gerar_das',
            ['period_key' => '2026-06', 'consolidation_date' => '2026-07-20'],
            ['periodoApuracao', 'dataConsolidacao'],
        ];
        yield 'PGDAS-D cobrança' => [
            'decl_pgdas_gerar_das_cobranca',
            ['period_key' => '2026-06'],
            ['periodoApuracao'],
        ];
        yield 'PGDAS-D processo' => [
            'decl_pgdas_gerar_das_processo',
            ['period_key' => '2026-06'],
            ['periodoApuracao'],
        ];
        yield 'PGDAS-D avulso' => [
            'decl_pgdas_gerar_das_avulso',
            [
                'period_key' => '2026-06',
                'business_payload' => ['listaTributos' => [['codigo' => 1001, 'valor' => 10.5]]],
            ],
            ['periodoApuracao', 'listaTributos'],
        ];
        yield 'DEFIS transmitir' => [
            'decl_defis_transmitir',
            [
                'calendar_year' => 2025,
                'business_payload' => ['inatividade' => 0, 'empresa' => []],
            ],
            ['ano', 'inatividade', 'empresa'],
        ];
        yield 'DCTFWeb gerar guia' => [
            'decl_dctfweb_gerar_guia',
            ['period_key' => '2026-06', 'category' => '40'],
            ['categoria', 'anoPA', 'mesPA'],
        ];
        yield 'DCTFWeb transmitir' => [
            'decl_dctfweb_transmitir',
            [
                'period_key' => '2026-06',
                'category' => '40',
                'signed_xml_base64' => base64_encode('<ConteudoDeclaracao/>'),
            ],
            ['categoria', 'anoPA', 'mesPA', 'xmlAssinadoBase64'],
        ];
        yield 'DCTFWeb guia em andamento' => [
            'decl_dctfweb_gerar_guia_andamento',
            ['period_key' => '2026-06', 'category' => '40', 'source_system_ids' => [1, 2]],
            ['categoria', 'anoPA', 'mesPA', 'idsSistemaOrigem'],
        ];
        yield 'MIT encerrar' => [
            'decl_mit_encerrar',
            ['period_key' => '2026-06', 'business_payload' => ['DadosIniciais' => ['SemMovimento' => true]]],
            ['PeriodoApuracao', 'DadosIniciais'],
        ];
    }

    public function test_trusted_identity_and_period_override_imported_values(): void
    {
        $payload = app(DeclarationMutationPayloadCodec::class)->encode(
            'decl_pgdas_entregar',
            [
                'period_key' => '2026-06',
                'business_payload' => [
                    'cnpjCompleto' => '99999999999999',
                    'pa' => 199901,
                    'indicadorTransmissao' => true,
                    'indicadorComparacao' => false,
                    'declaracao' => [],
                ],
            ],
            '11222333000181',
        );

        $this->assertSame('11222333000181', $payload['cnpjCompleto']);
        $this->assertSame(202606, $payload['pa']);
    }

    public function test_it_rejects_prospection_mutation_and_missing_contract_fields(): void
    {
        try {
            app(DeclarationMutationPayloadCodec::class)->encode(
                'decl_dasn_entregar',
                ['calendar_year' => 2025, 'business_payload' => []],
                '11222333000181',
            );
            $this->fail('Operação em prospecção deveria ser rejeitada.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('OPERATION_NOT_PRODUCTION', $e->getMessage());
        }

        try {
            app(DeclarationMutationPayloadCodec::class)->encode(
                'decl_defis_transmitir',
                ['calendar_year' => 2025, 'business_payload' => ['inatividade' => 0]],
                '11222333000181',
            );
            $this->fail('Campo obrigatório deveria ser rejeitado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('params.business_payload.empresa', $e->errors());
        }
    }

    public function test_it_rejects_technical_fields_before_encoding(): void
    {
        $this->expectException(ValidationException::class);

        app(DeclarationMutationPayloadCodec::class)->encode(
            'decl_mit_encerrar',
            [
                'period_key' => '2026-06',
                'business_payload' => ['idSistema' => 'MIT'],
            ],
            '11222333000181',
        );
    }
}
