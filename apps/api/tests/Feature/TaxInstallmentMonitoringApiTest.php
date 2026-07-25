<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxInstallmentMonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_monitor_all_productive_modalities(): void
    {
        $this->assertRoleCanMonitor(OfficeRole::Admin);
    }

    public function test_operator_can_monitor_all_productive_modalities(): void
    {
        $this->assertRoleCanMonitor(OfficeRole::Operator);
    }

    public function test_viewer_can_read_catalog_but_cannot_enqueue_monitoring(): void
    {
        Queue::fake();
        [$user, $client] = $this->actorAndClient(OfficeRole::Viewer);
        Sanctum::actingAs($user);

        $catalog = $this->getJson('/api/v1/fiscal/installments/modalities')
            ->assertOk()
            ->json('data');

        $this->assertCount(10, $catalog);
        $this->assertCount(8, array_filter($catalog, fn (array $item): bool => $item['executable']));
        $this->assertCount(2, array_filter($catalog, fn (array $item): bool => ! $item['executable']));

        $this->postJson('/api/v1/fiscal/installments/monitor', [
            'client_ids' => [$client->id],
        ])->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
    }

    public function test_bulk_monitor_rejects_cross_tenant_clients_before_any_run(): void
    {
        Queue::fake();
        [$user, $client] = $this->actorAndClient(OfficeRole::Operator);
        $otherClient = Client::factory()->forOffice(Office::factory()->create())->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/installments/monitor', [
            'client_ids' => [$client->id, $otherClient->id],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_SCOPE_INVALID');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
    }

    public function test_prospection_modality_cannot_be_enqueued_directly(): void
    {
        Queue::fake();
        [$user, $client] = $this->actorAndClient(OfficeRole::Admin);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/installments/runs', [
            'client_id' => $client->id,
            'modality' => 'PARC-PAEX',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'MODALITY_NOT_EXECUTABLE');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
    }

    private function assertRoleCanMonitor(OfficeRole $role): void
    {
        Queue::fake();
        [$user, $client] = $this->actorAndClient($role);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/installments/monitor', [
            'client_ids' => [$client->id],
            'correlation_id' => 'installments-api-test',
        ])->assertAccepted()
            ->assertJsonPath('data.clients', 1)
            ->assertJsonPath('data.requested_modalities_per_client', 8)
            ->assertJsonPath('data.accepted', 8)
            ->assertJsonPath('data.failed', 0);

        $this->assertSame(8, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseMissing('fiscal_monitoring_runs', ['service_code' => 'PARC-PAEX']);
        $this->assertDatabaseMissing('fiscal_monitoring_runs', ['service_code' => 'PARC-SIPADE']);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 8);
    }

    /** @return array{User, Client} */
    private function actorAndClient(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $client = Client::factory()->forOffice($office)->create();

        return [$user, $client];
    }
}
