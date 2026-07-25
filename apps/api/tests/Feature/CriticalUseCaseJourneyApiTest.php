<?php

namespace Tests\Feature;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalSituation;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\FiscalPendingItem;
use App\Models\Office;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CriticalUseCaseJourneyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_identity_and_tenant_switch_preserve_office_isolation(): void
    {
        $primary = Office::factory()->create(['name' => 'Office Primário']);
        $secondary = Office::factory()->create(['name' => 'Office Secundário']);
        $user = User::factory()->forOffice($primary, OfficeRole::Operator)->create();
        $secondary->users()->attach($user->id, [
            'role' => OfficeRole::Operator->value,
            'is_active' => true,
        ]);
        $user->forceFill(['selected_office_id' => $primary->id])->saveQuietly();

        $primaryClient = Client::factory()->forOffice($primary)->create(['legal_name' => 'Cliente Primário']);
        $secondaryClient = Client::factory()->forOffice($secondary)->create(['legal_name' => 'Cliente Secundário']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tenants/memberships')
            ->assertOk()
            ->assertJsonPath('data.current_office_id', $primary->id)
            ->assertJsonCount(2, 'data.memberships');

        $this->postJson('/api/v1/tenants/switch', ['office_id' => $secondary->id])
            ->assertOk()
            ->assertJsonPath('data.office.id', $secondary->id);

        $ids = $this->getJson('/api/v1/clients?per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($secondaryClient->id, $ids);
        $this->assertNotContains($primaryClient->id, $ids);
        Http::assertNothingSent();
    }

    public function test_client_catalog_is_tenant_scoped_and_viewer_cannot_mutate(): void
    {
        [$viewer, $office] = $this->actor(OfficeRole::Viewer);
        $otherOffice = Office::factory()->create();
        $own = Client::factory()->forOffice($office)->create(['legal_name' => 'Cliente Próprio']);
        $other = Client::factory()->forOffice($otherOffice)->create(['legal_name' => 'Cliente Externo']);
        Sanctum::actingAs($viewer);

        $ids = $this->getJson('/api/v1/clients?office_id='.$otherOffice->id.'&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$own->id],
            'is_active' => false,
            'inactive_reason' => 'Teste de permissão viewer',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_work_queue_is_tenant_scoped_and_viewer_cannot_create_process(): void
    {
        [$viewer, $office] = $this->actor(OfficeRole::Viewer);
        $otherOffice = Office::factory()->create();
        $ownClient = Client::factory()->forOffice($office)->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        $own = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $ownClient->id,
            'title' => 'Processo Próprio',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $own->id,
            'title' => 'Tarefa Própria',
        ]);
        $other = OperationalProcess::factory()->create([
            'office_id' => $otherOffice->id,
            'client_id' => $otherClient->id,
            'title' => 'Processo Externo',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $otherOffice->id,
            'operational_process_id' => $other->id,
            'title' => 'Tarefa Externa',
        ]);
        Sanctum::actingAs($viewer);

        $ids = $this->getJson('/api/v1/work/processes?office_id='.$otherOffice->id)
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $ownClient->id,
            'title' => 'Não permitido',
            'competence' => now()->format('Y-m'),
            'tasks' => [['title' => 'Tarefa']],
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_fiscal_monitoring_is_tenant_scoped_and_does_not_egress(): void
    {
        [$viewer, $office] = $this->actor(OfficeRole::Viewer);
        $otherOffice = Office::factory()->create();
        $ownClient = Client::factory()->forOffice($office)->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        $this->pending($office, $ownClient, 'OWN', 'Pendência própria');
        $this->pending($otherOffice, $otherClient, 'OTHER', 'Pendência externa');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/monitoring/insights')
            ->assertOk()
            ->assertJsonPath('data.pending.total', 1)
            ->assertJsonPath('data.pending.items.0.title', 'Pendência própria');
        Http::assertNothingSent();
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        return [$user, $office];
    }

    private function pending(Office $office, Client $client, string $code, string $title): void
    {
        FiscalPendingItem::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'code' => $code,
            'title' => $title,
            'severity' => FiscalFindingSeverity::Medium,
            'status' => FiscalPendingStatus::Open,
            'situation' => FiscalSituation::Pending,
            'logical_key' => strtolower($code).'-logical',
            'open_dedupe_key' => strtolower($code).'-open',
        ]);
    }
}
