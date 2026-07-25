<?php

namespace Tests\Feature;

use App\Enums\FiscalSituation;
use App\Enums\TaxInstallmentModality;
use App\Enums\TaxInstallmentParcelStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxInstallmentParcel;
use App\Models\TaxInstallmentPayment;
use App\Services\Integra\Parcelamento\ParcelamentoProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParcelamentoProjectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_each_parcel_and_payment_only_into_its_source_order(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $otherOffice = Office::factory()->create();
        $otherClient = Client::factory()->for($otherOffice)->create();

        TaxInstallmentOrder::query()->create([
            'office_id' => $otherOffice->id,
            'client_id' => $otherClient->id,
            'modality' => TaxInstallmentModality::Parcsn,
            'regime' => 'SN',
            'external_order_id' => '1',
            'situation' => FiscalSituation::Unknown->value,
            'source_system' => 'INTEGRA_PARCELAMENTO',
            'source_service' => 'PARCSN',
            'source_operation' => 'MONITOR',
            'observed_at' => now(),
        ]);

        $result = app(ParcelamentoProjectionService::class)->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Parcsn,
            ['pedidos' => [
                [
                    'numero' => '1',
                    'situacao' => 'Encerrado',
                    'dataPedido' => '2024-01-10',
                    'parcelas' => [[
                        'parcela' => '202402',
                        'vencimento' => '2024-02-29',
                        'valorCentavos' => 10010,
                        'situacaoFonte' => 'PAGA',
                    ]],
                    'pagamentos' => [
                        '202402' => [
                            'referencia' => 'DAS-1',
                            'pagamentoConfirmado' => true,
                            'dataPagamento' => '2024-02-20',
                            'valorPagoCentavos' => 10010,
                        ],
                    ],
                ],
                [
                    'numero' => '2',
                    'situacao' => 'Em andamento',
                    'dataPedido' => '2025-01-10',
                    'valorTotalCentavos' => 25025,
                    'quantidadeParcelas' => 2,
                    'parcelas' => [[
                        'parcela' => '202503',
                        'vencimento' => now()->addMonth()->toDateString(),
                        'valorCentavos' => 12513,
                        'disponivel' => true,
                        'situacaoFonte' => 'DISPONIVEL_PARA_EMISSAO',
                    ]],
                    'pagamentos' => [],
                ],
            ]],
        );

        $this->assertCount(2, $result['orders']);
        $this->assertCount(2, $result['parcels']);
        $this->assertCount(1, $result['payments']);

        $orders = TaxInstallmentOrder::query()
            ->where('office_id', $office->id)
            ->get()
            ->keyBy('external_order_id');
        $firstParcels = TaxInstallmentParcel::query()->where('order_id', $orders['1']->id)->get();
        $secondParcels = TaxInstallmentParcel::query()->where('order_id', $orders['2']->id)->get();
        $this->assertSame(['202402'], $firstParcels->pluck('parcel_key')->all());
        $this->assertSame(['202503'], $secondParcels->pluck('parcel_key')->all());
        $this->assertSame(TaxInstallmentParcelStatus::Paid, $firstParcels->first()->status);
        $this->assertSame('DAS-1', TaxInstallmentPayment::query()->where('order_id', $orders['1']->id)->value('payment_ref'));
        $this->assertDatabaseCount('tax_installment_orders', 3);
        $this->assertDatabaseHas('tax_installment_orders', [
            'office_id' => $otherOffice->id,
            'client_id' => $otherClient->id,
            'external_order_id' => '1',
        ]);
    }

    public function test_empty_orders_are_unknown_and_do_not_invent_regular_status(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();

        $result = app(ParcelamentoProjectionService::class)->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Relpsn,
            ['pedidos' => []],
        );

        $this->assertSame(FiscalSituation::Unknown, $result['situation']);
        $this->assertSame('PARCELAMENTO_SEM_PEDIDOS', $result['findings'][0]['code']);
        $this->assertDatabaseCount('tax_installment_orders', 0);
    }

    public function test_unassigned_available_parcels_are_not_persisted(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();

        $result = app(ParcelamentoProjectionService::class)->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Pertsn,
            [
                'pedidos' => [],
                'unassigned_available_parcels' => [['parcela' => '202601', 'valorCentavos' => 5000]],
            ],
        );

        $this->assertDatabaseCount('tax_installment_parcels', 0);
        $this->assertContains('PARCELAS_SEM_PEDIDO_CORRENTE', array_column($result['findings'], 'code'));
    }
}
