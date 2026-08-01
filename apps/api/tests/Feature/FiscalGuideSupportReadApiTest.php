<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\Enums\FiscalSourceProvenance;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\PagtoWebArrecadacaoReceipt;
use App\Models\PagtoWebPaymentCountObservation;
use App\Models\PagtoWebPaymentCountProjection;
use App\Models\PagtoWebPaymentListItem;
use App\Models\PagtoWebPaymentListObservation;
use App\Models\PagtoWebPaymentListProjection;
use App\Models\SicalcRevenueSupportObservation;
use App\Models\SicalcRevenueSupportProjection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class FiscalGuideSupportReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_local_guide_support_histories_without_side_effects(): void
    {
        Bus::fake();
        Http::fake();
        $executor = $this->createMock(SerproOperationExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(SerproOperationExecutor::class, $executor);

        $tenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $receipt = $this->makeReceipt($tenant, $client, 'receipt-local');
        $this->makePaymentCountHistory($tenant, $client);
        $this->makePaymentListHistory($tenant, $client);
        $this->makeSicalcHistory($tenant, $client, '0561');
        $this->makeSicalcHistory($tenant, $client, '1708');
        $this->actingAsTenantUser($viewer);

        $base = '/api/v1/fiscal/guides';

        $this->getJson($base.'/receipts/clients/'.$client->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $receipt->id)
            ->assertJsonMissingPath('data.items.0.receipt_vault_object_id')
            ->assertJsonMissingPath('data.items.0.receipt_sha256')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson($base.'/payment-count/clients/'.$client->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.current.payment_count', 7)
            ->assertJsonPath('data.history.0.payment_count', 7)
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            $base.'/payments/clients/'.$client->id
            .'/history?page=0&per_page=999',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.meta.page', 1)
            ->assertJsonPath('data.meta.per_page', 100)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            $base.'/revenue-support/clients/'.$client->id
            .'/history?codigo_receita=%200561%20',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.revenue_code', '0561')
            ->assertJsonCount(1, 'data.current')
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.current.0.revenue_code', '0561')
            ->assertJsonPath('data.provenance.serpro_called', false);

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_filters_reject_tenant_scope_and_hide_cross_tenant_clients(): void
    {
        Bus::fake();
        Http::fake();
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $this->actingAsTenantUser($viewer);
        $base = '/api/v1/fiscal/guides';

        $this->getJson(
            $base.'/payments/clients/'.$client->id
            .'/history?filters[tenant_id]='.$tenant->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        $this->getJson(
            $base.'/revenue-support/clients/'.$client->id
            .'/history?codigo_receita=ABC',
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_REVENUE_CODE');

        $paths = [
            '/receipts/clients/'.$otherClient->id.'/history',
            '/payment-count/clients/'.$otherClient->id.'/history',
            '/payments/clients/'.$otherClient->id.'/history',
            '/revenue-support/clients/'.$otherClient->id.'/history',
        ];

        foreach ($paths as $path) {
            $this->getJson($base.$path)->assertNotFound();
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_receipt_download_preserves_bytes_headers_and_sanitized_not_found(): void
    {
        Bus::fake();
        Http::fake();
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $receipt = $this->makeReceipt($tenant, $client, 'receipt-download');
        $failedReceipt = $this->makeReceipt(
            $tenant,
            $client,
            'receipt-vault-failure',
        );
        $foreignReceipt = $this->makeReceipt(
            $otherTenant,
            $otherClient,
            'receipt-foreign',
        );
        $bytes = '%PDF-1.4 pagtoweb';

        $objects = $this->createMock(SecureObjectStore::class);
        $objects->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(
                static function (string $objectId) use (
                    $receipt,
                    $failedReceipt,
                    $bytes,
                ): string {
                    if ($objectId === $receipt->receipt_vault_object_id) {
                        return $bytes;
                    }
                    if ($objectId === $failedReceipt->receipt_vault_object_id) {
                        throw new RuntimeException('vault unavailable');
                    }

                    throw new RuntimeException('unexpected object');
                },
            );
        $this->app->instance(SecureObjectStore::class, $objects);
        $this->actingAsTenantUser($viewer);

        $base = '/api/v1/fiscal/guides/receipts/clients/'.$client->id;

        $this->get($base.'/'.$receipt->id.'/download')
            ->assertOk()
            ->assertContent($bytes)
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Length', (string) strlen($bytes))
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="comprovante-pagtoweb-'
                .$receipt->id.'.pdf"',
            )
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->getJson($base.'/'.$foreignReceipt->id.'/download')
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertDontSee($foreignReceipt->receipt_vault_object_id)
            ->assertDontSee($foreignReceipt->receipt_sha256);

        $this->getJson($base.'/'.$failedReceipt->id.'/download')
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertDontSee($failedReceipt->receipt_vault_object_id)
            ->assertDontSee($failedReceipt->receipt_sha256);

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function actingAsTenantUser(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function makeReceipt(
        Tenant $tenant,
        Client $client,
        string $seed,
    ): PagtoWebArrecadacaoReceipt {
        return PagtoWebArrecadacaoReceipt::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'receipt_vault_object_id' => substr(
                    str_pad($seed, 26, '0'),
                    0,
                    26,
                ),
                'receipt_sha256' => hash('sha256', $seed),
                'receipt_mime_type' => 'application/pdf',
                'receipt_byte_size' => 512,
                'source_provenance' => FiscalSourceProvenance::SerproTrial,
                'observed_at' => now(),
            ]);
    }

    private function makePaymentCountHistory(
        Tenant $tenant,
        Client $client,
    ): void {
        $observation = PagtoWebPaymentCountObservation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'payment_count' => 7,
                'filter_summary' => ['period' => '2026-07'],
                'digest' => hash('sha256', 'payment-count-'.$client->id),
                'observed_at' => now(),
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
                'created_at' => now(),
            ]);

        PagtoWebPaymentCountProjection::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'payment_count' => 7,
                'filter_summary' => ['period' => '2026-07'],
                'last_valid_query_at' => now(),
                'last_observation_id' => $observation->id,
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
            ]);
    }

    private function makePaymentListHistory(
        Tenant $tenant,
        Client $client,
    ): void {
        $observation = PagtoWebPaymentListObservation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'filter_summary' => ['period' => '2026-07'],
                'returned_count' => 2,
                'digest' => hash('sha256', 'payment-list-'.$client->id),
                'observed_at' => now(),
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
                'created_at' => now(),
            ]);

        PagtoWebPaymentListProjection::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'last_observation_id' => $observation->id,
                'last_valid_query_at' => now(),
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
            ]);

        foreach (['DARF-001', 'DARF-002'] as $index => $document) {
            PagtoWebPaymentListItem::query()
                ->withoutGlobalScopes()
                ->create([
                    'observation_id' => $observation->id,
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'document_digest' => hash('sha256', $document),
                    'document_masked' => '***'.($index + 1),
                    'document_type' => 'DARF',
                    'revenue_code' => '0561',
                    'revenue_description' => 'Receita local',
                    'paid_on' => '2026-07-15',
                    'due_on' => '2026-07-20',
                    'total_amount' => 100 + $index,
                    'created_at' => now(),
                ]);
        }
    }

    private function makeSicalcHistory(
        Tenant $tenant,
        Client $client,
        string $revenueCode,
    ): void {
        $observation = SicalcRevenueSupportObservation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'revenue_code' => $revenueCode,
                'description' => 'Receita '.$revenueCode,
                'extensions' => [['code' => 'EXT-'.$revenueCode]],
                'extension_count' => 1,
                'digest' => hash(
                    'sha256',
                    'sicalc-'.$client->id.'-'.$revenueCode,
                ),
                'observed_at' => now(),
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
                'created_at' => now(),
            ]);

        SicalcRevenueSupportProjection::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'revenue_code' => $revenueCode,
                'description' => 'Receita '.$revenueCode,
                'extensions' => [['code' => 'EXT-'.$revenueCode]],
                'extension_count' => 1,
                'last_valid_query_at' => now(),
                'last_observation_id' => $observation->id,
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
            ]);
    }
}
