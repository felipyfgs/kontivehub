<?php

namespace Tests\Unit\Integra\Parcelamento;

use App\Services\Integra\Parcelamento\ParcelamentoOfficialCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParcelamentoOfficialCodecTest extends TestCase
{
    #[DataProvider('availableListKeys')]
    public function test_accepts_both_official_available_parcel_list_keys(string $key): void
    {
        $rows = (new ParcelamentoOfficialCodec)->availableParcels([
            $key => [['parcela' => 202604, 'valor' => '1.234,56']],
        ]);

        $this->assertSame('202604', $rows[0]['parcela']);
        $this->assertSame(123456, $rows[0]['valorCentavos']);
        $this->assertTrue($rows[0]['disponivel']);
    }

    public static function availableListKeys(): array
    {
        return [['listaParcela'], ['listaParcelas']];
    }

    public function test_normalizes_two_orders_without_mixing_parcels_or_payments(): void
    {
        $codec = new ParcelamentoOfficialCodec;
        $result = $codec->normalizeMonitor(
            ['parcelamentos' => [
                ['numero' => 1, 'dataDoPedido' => 20240110, 'situacao' => 'Encerrado'],
                ['numero' => 2, 'dataDoPedido' => 20250110, 'situacao' => 'Em andamento'],
            ]],
            [
                '1' => [
                    'numero' => 1,
                    'consolidacaoOriginal' => [
                        'valorTotalConsolidado' => 100.10,
                        'quantidadeParcelas' => 1,
                    ],
                    'demonstrativoPagamentos' => [[
                        'mesDaParcela' => 202402,
                        'vencimentoDoDas' => 20240229,
                        'dataDeArrecadacao' => 20240220,
                        'valorPago' => 100.10,
                    ]],
                ],
                '2' => [
                    'numero' => 2,
                    'consolidacaoOriginal' => [
                        'valorTotalConsolidado' => '250,25',
                        'quantidadeParcelas' => 2,
                        'dataConsolidacao' => 20250110123456,
                    ],
                    'demonstrativoPagamentos' => [[
                        'mesDaParcela' => 202502,
                        'vencimentoDoDas' => 20250228,
                        'dataDeArrecadacao' => 20250215,
                        'valorPago' => '125,12',
                    ]],
                ],
            ],
            ['listaParcelas' => [['parcela' => 202503, 'valor' => 125.13]]],
        );

        $orders = collect($result['pedidos'])->keyBy('numero');
        $this->assertSame(['202402'], collect($orders['1']['parcelas'])->pluck('parcela')->all());
        $this->assertSame(['202502', '202503'], collect($orders['2']['parcelas'])->pluck('parcela')->all());
        $this->assertArrayHasKey('202402', $orders['1']['pagamentos']);
        $this->assertArrayNotHasKey('202402', $orders['2']['pagamentos']);
        $this->assertSame(25025, $orders['2']['valorTotalCentavos']);
        $this->assertSame('2025-01-10 12:34:56', $orders['2']['dataConsolidacao']);
        $this->assertSame([], $result['unassigned_available_parcels']);
    }

    public function test_does_not_attach_available_parcels_when_all_orders_are_closed(): void
    {
        $result = (new ParcelamentoOfficialCodec)->normalizeMonitor(
            ['parcelamentos' => [['numero' => 1, 'situacao' => 'Encerrado a pedido do contribuinte']]],
            [],
            ['listaParcela' => [['parcela' => 202601, 'valor' => 50]]],
        );

        $this->assertSame([], $result['pedidos'][0]['parcelas']);
        $this->assertCount(1, $result['unassigned_available_parcels']);
    }

    public function test_payment_detail_uses_official_fields(): void
    {
        $payment = (new ParcelamentoOfficialCodec)->paymentDetail([
            'numeroDas' => 'DAS-1',
            'numeroParcelamento' => 77,
            'paDasGerado' => 202605,
            'dataPagamento' => 20260520,
            'valorPagoArrecadacao' => '99.95',
        ], '77', '202605');

        $this->assertTrue($payment['pagamentoConfirmado']);
        $this->assertSame(9995, $payment['valorPagoCentavos']);
        $this->assertSame('2026-05-20', $payment['dataPagamento']);
    }
}
