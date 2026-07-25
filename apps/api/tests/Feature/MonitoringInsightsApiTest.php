<?php

namespace Tests\Feature;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalSituation;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\FiscalPendingItem;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_read_monitoring_insights_shape(): void
    {
        $office = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $client = Client::factory()->for($office)->create();
        Sanctum::actingAs($viewer);

        FiscalPendingItem::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'code' => 'PEND_TEST',
            'title' => 'Pendência de teste',
            'detail' => 'Detalhe sanitizado',
            'severity' => FiscalFindingSeverity::High,
            'status' => FiscalPendingStatus::Open,
            'situation' => FiscalSituation::Pending,
            'logical_key' => 'pend-test-1',
            'open_dedupe_key' => 'pend-test-1-open',
        ]);

        $response = $this->getJson('/api/v1/fiscal/monitoring/insights')
            ->assertOk()
            ->assertJsonPath('data.kpis.clients_total', 1)
            ->assertJsonPath('data.kpis.pending_open', 1)
            ->assertJsonStructure([
                'data' => [
                    'as_of',
                    'kpis' => ['clients_total', 'pending_open', 'findings_active', 'modules_with_error'],
                    'pending' => ['total', 'by_severity', 'items'],
                    'rbt12' => ['clients'],
                    'mailbox' => ['buckets'],
                    'notifications' => ['items'],
                    'declarations_absence' => ['up_to_date_count', 'open_count', 'by_obligation'],
                    'sitfis' => ['counters'],
                    'obligations_progress',
                ],
            ]);

        $payload = $response->json('data');
        $this->assertIsArray($payload['obligations_progress']);
        $codes = array_column($payload['obligations_progress'], 'code');
        $this->assertContains('DIRF', $codes);
        $dirf = collect($payload['obligations_progress'])->firstWhere('code', 'DIRF');
        $this->assertSame('UNSUPPORTED', $dirf['coverage'] ?? null);
        $this->assertArrayHasKey('completed', $dirf);
        $this->assertNull($dirf['completed']);
        $this->assertNull($dirf['total']);
        $this->assertArrayNotHasKey('office_id', $payload['pending']['items'][0] ?? []);
        $this->assertNull($payload['partial_errors'] ?? null);
    }

    public function test_insights_require_authentication_and_office_membership(): void
    {
        $this->getJson('/api/v1/fiscal/monitoring/insights')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/fiscal/monitoring/insights')->assertForbidden();
    }

    public function test_insights_do_not_leak_other_office_pending(): void
    {
        $office = Office::factory()->create();
        $other = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $client = Client::factory()->for($office)->create();
        $otherClient = Client::factory()->for($other)->create();
        Sanctum::actingAs($viewer);

        FiscalPendingItem::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'code' => 'OWN',
            'title' => 'Própria',
            'severity' => FiscalFindingSeverity::Medium,
            'status' => FiscalPendingStatus::Open,
            'situation' => FiscalSituation::Pending,
            'logical_key' => 'own-1',
            'open_dedupe_key' => 'own-1-open',
        ]);
        FiscalPendingItem::query()->create([
            'office_id' => $other->id,
            'client_id' => $otherClient->id,
            'code' => 'OTHER',
            'title' => 'Outra office',
            'severity' => FiscalFindingSeverity::Critical,
            'status' => FiscalPendingStatus::Open,
            'situation' => FiscalSituation::Pending,
            'logical_key' => 'other-1',
            'open_dedupe_key' => 'other-1-open',
        ]);

        $this->getJson('/api/v1/fiscal/monitoring/insights')
            ->assertOk()
            ->assertJsonPath('data.kpis.clients_total', 1)
            ->assertJsonPath('data.kpis.pending_open', 1)
            ->assertJsonPath('data.pending.total', 1)
            ->assertJsonPath('data.pending.items.0.title', 'Própria');
    }
}
