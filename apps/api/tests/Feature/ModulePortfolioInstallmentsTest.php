<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TaxInstallmentModality;
use App\Models\Client;
use App\Models\Office;
use App\Models\TaxInstallmentOrder;
use App\Models\User;
use App\Services\Integra\Parcelamento\ParcelamentoProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModulePortfolioInstallmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_aggregates_all_modalities_and_scopes_filtered_enrichment(): void
    {
        config()->set('features.global_enabled', true);
        config()->set('features.modules.parcelamentos.enabled', true);

        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->forOffice($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = app(ParcelamentoProjectionService::class);
        $projection->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Parcsn,
            ['pedidos' => [$this->orderBody('SN-1', 10000, '202608', true)]],
        );
        $projection->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Parcmei,
            ['pedidos' => [$this->orderBody('MEI-1', 20000, '202609')]],
        );

        $otherOffice = Office::factory()->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        $projection->projectFromMonitorBody(
            $otherOffice,
            $otherClient,
            TaxInstallmentModality::Pertsn,
            ['pedidos' => [$this->orderBody('OTHER-1', 99999, '202610')]],
        );

        Sanctum::actingAs($user);
        $row = collect($this->getJson('/api/v1/fiscal/modules/installments/clients?per_page=50')
            ->assertOk()
            ->json('data'))
            ->firstWhere('client_id', $client->id);

        $this->assertSame(2, $row['detail']['order_count']);
        $this->assertSame(['PARCMEI', 'PARCSN'], $row['detail']['modalities']);
        $this->assertSame(30000, $row['detail']['total_amount_cents']);
        $this->assertSame(2, $row['detail']['parcel_count']);
        $this->assertCount(2, $row['detail']['orders']);
        $this->assertStringNotContainsString((string) $otherClient->id, $row['detail']['links']['orders']);

        $filtered = $this->getJson(
            '/api/v1/fiscal/modules/installments/clients?per_page=50&modality=PARCSN'
        )->assertOk()->json('data.0');

        $this->assertSame($client->id, $filtered['client_id']);
        $this->assertSame(1, $filtered['detail']['order_count']);
        $this->assertSame(['PARCSN'], $filtered['detail']['modalities']);
        $this->assertSame(10000, $filtered['detail']['total_amount_cents']);
        $this->assertStringContainsString('modality=PARCSN', $filtered['detail']['links']['orders']);

        $overview = $this->getJson(
            '/api/v1/fiscal/modules/installments/overview?modality=PARCSN'
        )->assertOk();
        $tabCounts = $overview->json('data.metrics.tab_counts');

        $overview->assertJsonPath('data.total_clients', 1);
        $this->assertSame(1, $tabCounts['all']);
        $this->assertSame(1, $tabCounts['PARCSN']);
        $this->assertSame(1, $tabCounts['PARCMEI']);
        $this->assertSame(0, $tabCounts['PERTSN']);
        $this->assertSame(0, $tabCounts['PARC-PAEX']);
        $this->assertSame(0, $tabCounts['PARC-SIPADE']);
    }

    public function test_order_detail_returns_only_local_projected_parcels_and_payments(): void
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $client = Client::factory()->forOffice($office)->create();
        app(ParcelamentoProjectionService::class)->projectFromMonitorBody(
            $office,
            $client,
            TaxInstallmentModality::Parcsn,
            ['pedidos' => [$this->orderBody('SN-DETAIL', 10000, '202608', true)]],
        );
        $order = TaxInstallmentOrder::query()->where('office_id', $office->id)->firstOrFail();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/fiscal/installments/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.external_order_id', 'SN-DETAIL')
            ->assertJsonCount(1, 'data.parcels')
            ->assertJsonCount(1, 'data.payments');
    }

    /** @return array<string, mixed> */
    private function orderBody(string $number, int $amount, string $parcel, bool $paid = false): array
    {
        return [
            'numero' => $number,
            'situacao' => 'Em andamento',
            'dataPedido' => '2026-07-01',
            'valorTotalCentavos' => $amount,
            'quantidadeParcelas' => 1,
            'parcelas' => [[
                'parcela' => $parcel,
                'vencimento' => '2026-08-31',
                'valorCentavos' => $amount,
                'situacaoFonte' => $paid ? 'PAGA' : 'DISPONIVEL_PARA_EMISSAO',
            ]],
            'pagamentos' => $paid ? [
                $parcel => [
                    'referencia' => 'PAY-'.$number,
                    'pagamentoConfirmado' => true,
                    'dataPagamento' => '2026-07-20',
                    'valorPagoCentavos' => $amount,
                ],
            ] : [],
        ];
    }
}
