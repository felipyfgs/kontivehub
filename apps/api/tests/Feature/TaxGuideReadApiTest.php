<?php

namespace Tests\Feature;

use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxGuideRiskLevel;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\TaxGuide;
use App\Models\TaxGuidePaymentConfirmation;
use App\Models\TaxGuideVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxGuideReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_lists_and_reads_current_tenant_guide_details(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        [$guide, $version] = $this->guide($tenant, $client, 'own-guide');
        $confirmation = $this->confirmation($tenant, $guide, $version);
        $this->guide($otherTenant, $otherClient, 'other-guide');
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/guides?client_id='.$client->id
            .'&payment_status=UNKNOWN&per_page=10',
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $guide->id)
            ->assertJsonPath('data.0.current_version.id', $version->id)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('payment_counters.UNKNOWN', 1);

        $this->getJson('/api/v1/fiscal/guides/'.$guide->id)
            ->assertOk()
            ->assertJsonPath('data.id', $guide->id)
            ->assertJsonPath('data.versions.0.id', $version->id)
            ->assertJsonPath(
                'data.payment_confirmations.0.id',
                $confirmation->id,
            )
            ->assertJsonMissingPath('data.versions.0.vault_object_id')
            ->assertJsonMissingPath(
                'data.payment_confirmations.0.vault_object_id',
            );
    }

    public function test_filters_and_download_boundary_reject_tenant_scope_and_hide_foreign_guide(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        [$otherGuide] = $this->guide(
            $otherTenant,
            $otherClient,
            'foreign-guide',
        );
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/guides?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/fiscal/guides?payment_status=INVALID')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_status']);
        $this->getJson('/api/v1/fiscal/guides?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
        $this->getJson(
            '/api/v1/fiscal/guides/downloads/invalid-token'
            .'?tenant_id='.$tenant->id,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
        $this->getJson('/api/v1/fiscal/guides/'.$otherGuide->id)
            ->assertNotFound();
    }

    /** @return array{TaxGuide, TaxGuideVersion} */
    private function guide(
        Tenant $tenant,
        Client $client,
        string $logicalKey,
    ): array {
        $guide = TaxGuide::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'test.issue',
            'system_code' => 'TEST',
            'service_code' => 'GUIDE',
            'operation_code' => 'EMITIR_GUIA',
            'competence_period_key' => '2026-07',
            'logical_key' => $logicalKey,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'amount_cents' => 12345,
            'currency' => 'BRL',
            'identifier_code' => 'GUIDE-'.$logicalKey,
        ]);
        $version = TaxGuideVersion::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tax_guide_id' => $guide->id,
            'version_number' => 1,
            'is_current' => true,
            'emission_status' => TaxGuideEmissionStatus::Confirmed,
            'identifier_code' => $guide->identifier_code,
            'amount_cents' => 12345,
            'currency' => 'BRL',
            'content_sha256' => str_repeat('a', 64),
            'vault_object_id' => '01J00000000000000000000000',
            'content_type' => 'application/pdf',
            'byte_size' => 512,
            'idempotency_key' => 'idempotency-'.$logicalKey,
            'risk_level' => TaxGuideRiskLevel::Standard,
        ]);
        $guide->forceFill(['current_version_id' => $version->id])->save();

        return [$guide, $version];
    }

    private function confirmation(
        Tenant $tenant,
        TaxGuide $guide,
        TaxGuideVersion $version,
    ): TaxGuidePaymentConfirmation {
        return TaxGuidePaymentConfirmation::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'tax_guide_id' => $guide->id,
                'tax_guide_version_id' => $version->id,
                'source' => 'TEST',
                'external_id' => 'PAYMENT-'.$guide->id,
                'amount_cents' => 12345,
                'currency' => 'BRL',
                'paid_at' => now(),
                'evidence_digest' => str_repeat('b', 64),
            ]);
    }
}
