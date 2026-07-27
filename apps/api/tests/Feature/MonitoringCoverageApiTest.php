<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringCoverageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_read_the_sanitized_monitoring_contract(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/fiscal/monitoring/coverage')
            ->assertOk()
            ->assertJsonPath('data.totals.surfaces', 15)
            ->assertJsonPath('data.totals.catalog_operations', 119)
            ->assertJsonPath('data.surfaces.0.surface_key', 'monitoring_dashboard')
            ->assertJsonPath('data.surfaces.1.capabilities.0.actions.0.operation_class', 'READ')
            ->assertJsonMissingPath('data.surfaces.0.operation_key')
            ->assertJsonMissingPath('data.surfaces.0.tenant_id');

        $this->assertSanitized($response->json());
    }

    public function test_monitoring_contract_requires_authentication_and_tenant_membership(): void
    {
        $this->getJson('/api/v1/fiscal/monitoring/coverage')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/fiscal/monitoring/coverage')->assertForbidden();
    }

    public function test_manual_inventory_is_sanitized_and_cannot_cross_the_current_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/fiscal/manual-consults?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.meta.client_id', $client->id)
            ->assertJsonPath('data.meta.serpro_called', false)
            ->assertJsonMissingPath('data.actions.0.operation_hint');

        $this->assertNotEmpty($response->json('data.actions'));
        $this->assertNotContains(true, array_column($response->json('data.actions'), 'executable'));
        $this->assertContains(
            'permission_denied',
            array_column($response->json('data.actions'), 'eligibility'),
        );
        $this->assertStringNotContainsString(
            '.',
            (string) $response->json('data.actions.0.action_id'),
        );
        $this->assertSanitized($response->json());

        $this->getJson('/api/v1/fiscal/manual-consults?client_id='.$otherClient->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'CLIENT_NOT_FOUND');

        $this->getJson('/api/v1/fiscal/manual-consults?tenant_id='.$otherTenant->id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');
    }

    public function test_manual_inventory_preserves_last_snapshot_when_refresh_fails(): void
    {
        config()->set('fiscal_monitoring.projection.snapshot_freshness_ttl_seconds', 3600);
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $client = Client::factory()->for($tenant)->create();
        $successful = $this->createPgdasdRun(
            $tenant,
            $client,
            FiscalRunStatus::Completed,
            FiscalRunResult::Success,
            'monitoring-state:success',
        );
        $snapshot = FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'run_id' => $successful->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'CONSULTAR_DECLARACAO',
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'situation' => FiscalSituation::UpToDate,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['sanitized' => true],
            'observed_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);
        $this->createPgdasdRun(
            $tenant,
            $client,
            FiscalRunStatus::Failed,
            FiscalRunResult::Failed,
            'monitoring-state:failed',
            'UPSTREAM_TIMEOUT',
        );
        Sanctum::actingAs($viewer);

        $actions = $this->getJson('/api/v1/fiscal/manual-consults?client_id='.$client->id)
            ->assertOk()
            ->json('data.actions');
        $action = collect($actions)->first(
            static fn (array $row): bool => str_ends_with(
                (string) $row['action_id'],
                'pgdasd:consdeclaracao',
            ),
        );

        $this->assertIsArray($action);
        $this->assertSame('FAILED', $action['last_result_summary']['state']);
        $this->assertSame('UPSTREAM_TIMEOUT', $action['last_result_summary']['reason_code']);
        $this->assertTrue($action['last_result_summary']['has_preserved_snapshot']);
        $this->assertSame($snapshot->id, $action['last_result_summary']['last_snapshot']['snapshot_id']);
        $this->assertSame('STALE', $action['last_result_summary']['freshness']['state']);
        $this->assertArrayNotHasKey('normalized', $action['last_result_summary']['last_snapshot']);
    }

    private function createPgdasdRun(
        Tenant $tenant,
        Client $client,
        FiscalRunStatus $status,
        FiscalRunResult $result,
        string $idempotencyKey,
        ?string $errorCode = null,
    ): FiscalMonitoringRun {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'CONSULTAR_DECLARACAO',
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'result' => $result,
            'coverage' => FiscalCoverage::Unknown,
            'error_code' => $errorCode,
            'finished_at' => now(),
        ]);
    }

    /** @param array<string|int, mixed> $payload */
    private function assertSanitized(array $payload): void
    {
        $forbidden = [
            'operation_key',
            'operation_hint',
            'idSistema',
            'idServico',
            'handler',
            'run_codes',
            'required_proxy_powers',
            'tenant_id',
            'business_data',
        ];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden);
            }
            if (is_array($value)) {
                $this->assertSanitized($value);
            }
        }
    }
}
