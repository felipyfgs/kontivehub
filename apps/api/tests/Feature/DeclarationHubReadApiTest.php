<?php

namespace Tests\Feature;

use App\Enums\FiscalSituation;
use App\Enums\TaxDeliveryEvidenceKind;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxPeriodGranularity;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\TaxDeliveryEvidence;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\TaxObligationVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeclarationHubReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_current_tenant_projection_summary_and_evidence(): void
    {
        $definition = $this->definition();
        $version = $this->version($definition);
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $projection = $this->projection(
            $tenant,
            $client,
            $definition,
            $version,
            '2026-07',
        );
        $evidence = $this->evidence($tenant, $projection);
        $this->projection(
            $otherTenant,
            $otherClient,
            $definition,
            $version,
            '2026-08',
        );
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/declarations?client_id='.$client->id
            .'&is_open=true&per_page=10',
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projection->id)
            ->assertJsonPath('data.0.obligation_code', $definition->code)
            ->assertJsonPath('data.0.deep_links.self',
                '/api/v1/fiscal/declarations/'.$projection->id)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 10)
            ->assertJsonPath('total', 1);

        $this->getJson(
            '/api/v1/fiscal/declarations/summary?client_id='.$client->id,
        )->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.obligation_code', $definition->code)
            ->assertJsonPath('data.0.total', 1);

        $this->getJson('/api/v1/fiscal/declarations/'.$projection->id)
            ->assertOk()
            ->assertJsonPath('data.id', $projection->id)
            ->assertJsonPath('data.evidences.0.id', $evidence->id)
            ->assertJsonPath('data.due_rule_snapshot.rule', 'test');

        $this->getJson(
            '/api/v1/fiscal/declarations/'.$projection->id
            .'/evidences/'.$evidence->id,
        )->assertOk()
            ->assertJsonPath('data.id', $evidence->id)
            ->assertJsonPath('data.kind',
                TaxDeliveryEvidenceKind::OfficialReceipt->value);
    }

    public function test_filters_are_validated_and_cross_tenant_records_are_hidden(): void
    {
        $definition = $this->definition();
        $version = $this->version($definition);
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $otherProjection = $this->projection(
            $otherTenant,
            $otherClient,
            $definition,
            $version,
            '2026-09',
        );
        $otherEvidence = $this->evidence($otherTenant, $otherProjection);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/declarations?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/fiscal/declarations?is_open=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_open']);
        $this->getJson(
            '/api/v1/fiscal/declarations?tenant_id='.$tenant->id,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $this->getJson(
            '/api/v1/fiscal/declarations/'.$otherProjection->id,
        )->assertNotFound();
        $this->getJson(
            '/api/v1/fiscal/declarations/'.$otherProjection->id
            .'/evidences/'.$otherEvidence->id,
        )->assertNotFound();
    }

    private function definition(): TaxObligationDefinition
    {
        return TaxObligationDefinition::query()->create([
            'code' => 'TEST_DECLARATION',
            'name' => 'Declaração de teste',
            'module_key' => 'declarations',
            'system_code' => 'TEST',
            'service_code' => 'READ',
            'period_granularity' => TaxPeriodGranularity::Monthly,
            'is_active' => true,
        ]);
    }

    private function version(
        TaxObligationDefinition $definition,
    ): TaxObligationVersion {
        return TaxObligationVersion::query()->create([
            'obligation_definition_id' => $definition->id,
            'version' => 1,
            'rule_key' => 'test-rule-v1',
            'default_applicability' => TaxObligationApplicability::Applicable,
            'timezone' => 'America/Sao_Paulo',
            'effective_from' => now()->subYear(),
            'is_current' => true,
        ]);
    }

    private function projection(
        Tenant $tenant,
        Client $client,
        TaxObligationDefinition $definition,
        TaxObligationVersion $version,
        string $periodKey,
    ): TaxObligationProjection {
        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'obligation_definition_id' => $definition->id,
                'obligation_version_id' => $version->id,
                'period_key' => $periodKey,
                'period_year' => (int) substr($periodKey, 0, 4),
                'period_month' => (int) substr($periodKey, 5, 2),
                'applicability' => TaxObligationApplicability::Applicable,
                'situation' => FiscalSituation::Pending,
                'delivery_status' => FiscalSituation::Pending,
                'due_rule_snapshot' => ['rule' => 'test'],
                'due_history' => [],
                'is_open' => true,
            ]);
    }

    private function evidence(
        Tenant $tenant,
        TaxObligationProjection $projection,
    ): TaxDeliveryEvidence {
        return TaxDeliveryEvidence::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'projection_id' => $projection->id,
                'kind' => TaxDeliveryEvidenceKind::OfficialReceipt,
                'receipt_number' => 'REC-TEST',
                'is_conclusive' => true,
                'source' => 'TEST',
                'observed_at' => now(),
            ]);
    }
}
