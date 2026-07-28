<?php

namespace Tests\Feature;

use App\Enums\FiscalSourceProvenance;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalPnrRenunciation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PnrRenunciationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_only_current_tenant_sanitized_renunciations(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $renunciation = $this->renunciation($tenant, $client, 1001);
        $this->renunciation($otherTenant, $otherClient, 2002);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/clients/'.$client->id.'/pnr-renunciations')
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonCount(1, 'data.renunciations')
            ->assertJsonPath(
                'data.renunciations.0.id',
                $renunciation->id,
            )
            ->assertJsonPath(
                'data.renunciations.0.source_provenance',
                FiscalSourceProvenance::SerproReal->value,
            )
            ->assertJsonPath(
                'data.renunciations.0.receipt.mime_type',
                'application/pdf',
            )
            ->assertJsonMissingPath(
                'data.renunciations.0.receipt_vault_object_id',
            )
            ->assertJsonMissingPath('data.renunciations.0.receipt_sha256');
    }

    public function test_read_rejects_tenant_input_and_hides_other_tenant_client(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/clients/'.$otherClient->id
            .'/pnr-renunciations',
        )->assertNotFound();

        $this->getJson(
            '/api/v1/fiscal/clients/'.$otherClient->id
            .'/pnr-renunciations?tenant_id='.$tenant->id,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
    }

    private function renunciation(
        Tenant $tenant,
        Client $client,
        int $renunciationId,
    ): FiscalPnrRenunciation {
        return FiscalPnrRenunciation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'contributor_cnpj' => '12345678000190',
                'renunciation_id' => $renunciationId,
                'status' => 'CONFIRMED',
                'source_provenance' => FiscalSourceProvenance::SerproReal,
                'summary_sanitized' => ['status_label' => 'Confirmada'],
                'observed_at' => now(),
                'refreshed_at' => now(),
                'receipt_vault_object_id' => '01J00000000000000000000000',
                'receipt_sha256' => str_repeat('a', 64),
                'receipt_mime_type' => 'application/pdf',
                'receipt_byte_size' => 512,
                'receipt_observed_at' => now(),
            ]);
    }
}
