<?php

namespace Tests\Feature;

use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxGuideRiskLevel;
use App\Enums\TaxInstallmentModality;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\TaxInstallmentOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\Parcelamento\ParcelamentoProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxInstallmentReadResourceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_resources_preserve_flat_pages_details_and_tenant_isolation(): void
    {
        Bus::fake();
        Http::fake();
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $projection = app(ParcelamentoProjectionService::class);
        $projection->projectFromMonitorBody(
            $tenant,
            $client,
            TaxInstallmentModality::Parcsn,
            ['pedidos' => [$this->orderBody('LOCAL-ORDER')]],
        );
        $projection->projectFromMonitorBody(
            $otherTenant,
            $otherClient,
            TaxInstallmentModality::Parcmei,
            ['pedidos' => [$this->orderBody('FOREIGN-ORDER')]],
        );
        $order = TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $foreignOrder = TaxInstallmentOrder::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $otherTenant->id)
            ->firstOrFail();
        [$guide, $version] = $this->makeGuide($tenant, $client);
        $this->makeGuide($otherTenant, $otherClient);
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/installments/orders?client_id='.$client->id
            .'&modality=parcsn&per_page=1',
        )
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.external_order_id', 'LOCAL-ORDER')
            ->assertJsonPath('data.0.modality', 'PARCSN')
            ->assertJsonMissingPath('meta');

        $this->getJson(
            '/api/v1/fiscal/installments/parcels?client_id='.$client->id
            .'&order_id='.$order->id.'&modality=PARCSN&per_page=1',
        )
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.modality', 'PARCSN');

        $this->getJson(
            '/api/v1/fiscal/installments/guides?client_id='.$client->id
            .'&per_page=1',
        )
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $guide->id)
            ->assertJsonPath('data.0.current_version.id', $version->id)
            ->assertJsonMissingPath('data.0.current_version.vault_object_id');

        $this->getJson('/api/v1/fiscal/installments/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.external_order_id', 'LOCAL-ORDER')
            ->assertJsonCount(1, 'data.parcels')
            ->assertJsonCount(1, 'data.payments');

        $this->getJson(
            '/api/v1/fiscal/installments/orders/'.$foreignOrder->id,
        )->assertNotFound();
        $this->getJson(
            '/api/v1/fiscal/installments/orders?client_id='
            .$otherClient->id,
        )
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function orderBody(string $number): array
    {
        return [
            'numero' => $number,
            'situacao' => 'Em andamento',
            'dataPedido' => '2026-07-01',
            'valorTotalCentavos' => 10000,
            'quantidadeParcelas' => 1,
            'parcelas' => [[
                'parcela' => '202608',
                'vencimento' => '2026-08-31',
                'valorCentavos' => 10000,
                'situacaoFonte' => 'PAGA',
            ]],
            'pagamentos' => [
                '202608' => [
                    'referencia' => 'PAY-'.$number,
                    'pagamentoConfirmado' => true,
                    'dataPagamento' => '2026-07-20',
                    'valorPagoCentavos' => 10000,
                ],
            ],
        ];
    }

    /** @return array{TaxGuide, TaxGuideVersion} */
    private function makeGuide(
        Tenant $tenant,
        Client $client,
    ): array {
        $guide = TaxGuide::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'parcsn.gerardas',
            'system_code' => 'INTEGRA_PARCELAMENTO',
            'service_code' => 'PARCSN',
            'operation_code' => 'EMITIR_DOCUMENTO',
            'competence_period_key' => '2026-08',
            'logical_key' => 'installment-guide-'.$client->id,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'amount_cents' => 10000,
            'currency' => 'BRL',
            'identifier_code' => 'GUIDE-'.$client->id,
        ]);
        $version = TaxGuideVersion::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'tax_guide_id' => $guide->id,
                'version_number' => 1,
                'is_current' => true,
                'emission_status' => TaxGuideEmissionStatus::Confirmed,
                'identifier_code' => $guide->identifier_code,
                'amount_cents' => 10000,
                'currency' => 'BRL',
                'content_sha256' => hash('sha256', 'guide-'.$client->id),
                'vault_object_id' => '01J'.str_pad(
                    (string) $client->id,
                    23,
                    '0',
                    STR_PAD_LEFT,
                ),
                'content_type' => 'application/pdf',
                'byte_size' => 512,
                'idempotency_key' => 'guide-version-'.$client->id,
                'risk_level' => TaxGuideRiskLevel::Standard,
            ]);
        $guide->forceFill(['current_version_id' => $version->id])->save();

        return [$guide, $version];
    }
}
