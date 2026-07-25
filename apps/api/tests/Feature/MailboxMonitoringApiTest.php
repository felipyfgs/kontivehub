<?php

namespace Tests\Feature;

use App\Enums\FiscalProfile;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\OfficeRole;
use App\Enums\SerproConsumptionClass;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\MailboxMessage;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Models\SerproPriceTier;
use App\Models\SerproPriceVersion;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailboxMonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fiscal.profile' => FiscalProfile::Dev->value, 'fiscal.kill_switch' => false]);
    }

    public function test_configuration_is_tenant_scoped_and_rejects_office_id(): void
    {
        [$office] = $this->tenant();
        $other = Office::factory()->create();
        MailboxMonitoringSetting::query()->create(['office_id' => $other->id, 'enabled' => false]);

        $this->getJson('/api/v1/fiscal/mailbox/monitoring')
            ->assertOk()
            ->assertJsonPath('data.mode', 'ECONOMICO')
            ->assertJsonMissingPath('data.office_id');

        $this->patchJson('/api/v1/fiscal/mailbox/monitoring', [
            'office_id' => $other->id,
            'enabled' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('mailbox_monitoring_settings', ['office_id' => $office->id]);
        $this->assertDatabaseHas('mailbox_monitoring_settings', ['office_id' => $other->id, 'enabled' => false]);
    }

    public function test_preview_and_confirm_update_now_are_costed_sanitized_and_idempotent(): void
    {
        Queue::fake();
        [$office, $client] = $this->tenant();
        Establishment::factory()->forClient($client, '11222333000181')->create();
        ClientProcuracaoSync::factory()->forClient($client)->authorized()->create();
        $this->price('LISTAR', 250_000);

        $this->postJson('/api/v1/fiscal/mailbox/monitoring/preview', ['force_all' => true])
            ->assertOk()
            ->assertJsonPath('data.clients_to_list', 1)
            ->assertJsonPath('data.cost.price_source', 'SHADOW')
            ->assertJsonMissingPath('data.client_ids')
            ->assertJsonMissingPath('data.reasons');

        $payload = ['force_all' => true, 'idempotency_key' => 'mailbox-test-0001'];
        $this->postJson('/api/v1/fiscal/mailbox/monitoring/sync', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.runs_enqueued', 1);
        $this->postJson('/api/v1/fiscal/mailbox/monitoring/sync', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.duplicate', true);

        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
        $this->assertDatabaseHas('fiscal_monitoring_runs', [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'operation_code' => 'LISTAR',
        ]);
    }

    public function test_detail_on_demand_requires_cost_preview_and_creates_one_run_per_isn(): void
    {
        Queue::fake();
        [$office, $client] = $this->tenant();
        $this->price('DETALHE', 100_000);
        $externalId = 'ISN-DETAIL-1';
        $message = MailboxMessage::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash($office->id, $client->id, $externalId),
            'source' => MailboxSource::CaixaPostal,
            'sensitivity_class' => 'FISCAL_RESTRICTED',
            'subject_preview' => 'Sem corpo',
            'triage_status' => MailboxTriageStatus::New,
            'has_body' => false,
            'attachment_count' => 0,
        ]);

        $this->getJson('/api/v1/fiscal/mailbox/messages/'.$message->id.'/detail-preview')
            ->assertOk()
            ->assertJsonPath('data.has_body', false)
            ->assertJsonPath('data.cost.estimated_cost_micros', 100_000);
        $first = $this->postJson('/api/v1/fiscal/mailbox/messages/'.$message->id.'/detail')
            ->assertAccepted()->json('data.run_id');
        $second = $this->postJson('/api/v1/fiscal/mailbox/messages/'.$message->id.'/detail')
            ->assertAccepted()->json('data.run_id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 1);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }

    /** @return array{Office,Client} */
    private function tenant(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $client = Client::factory()->for($office)->create();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        app(CurrentOffice::class)->resolve($user);

        return [$office, $client];
    }

    private function price(string $operation, int $micros): void
    {
        SerproPriceVersion::query()->update(['is_active' => false]);
        $version = SerproPriceVersion::query()->create([
            'version_code' => 'mailbox-api-shadow', 'name' => 'Mailbox teste',
            'effective_from' => now()->subDay(), 'is_active' => true, 'currency' => 'BRL',
            'eligibility' => 'SHADOW', 'authorizes_production' => false,
        ]);
        SerproPriceTier::query()->create([
            'price_version_id' => $version->id,
            'consumption_class' => SerproConsumptionClass::Consulta,
            'system_code' => 'INTEGRA_CAIXAPOSTAL', 'service_code' => 'CAIXA_POSTAL',
            'operation_code' => $operation, 'min_quantity' => 1,
            'unit_cost_micros' => $micros, 'sort_order' => 1,
        ]);
    }
}
