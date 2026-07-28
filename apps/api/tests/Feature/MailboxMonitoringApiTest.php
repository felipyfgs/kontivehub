<?php

namespace Tests\Feature;

use App\Enums\FiscalProfile;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\SerproConsumptionClass;
use App\Enums\TenantRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\MailboxMessage;
use App\Models\MailboxMonitoringSetting;
use App\Models\SerproPriceTier;
use App\Models\SerproPriceVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use App\Support\CurrentTenant;
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

    public function test_configuration_is_tenant_scoped_and_rejects_tenant_id(): void
    {
        [$tenant] = $this->tenant();
        $other = Tenant::factory()->create();
        MailboxMonitoringSetting::query()->create(['tenant_id' => $other->id, 'enabled' => false]);

        $this->getJson('/api/v1/fiscal/mailbox/monitoring')
            ->assertOk()
            ->assertJsonPath('data.mode', 'ECONOMICO')
            ->assertJsonMissingPath('data.tenant_id');
        $this->getJson(
            '/api/v1/fiscal/mailbox/monitoring?tenant_id='.$other->id,
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $this->patchJson('/api/v1/fiscal/mailbox/monitoring', [
            'tenant_id' => $other->id,
            'enabled' => true,
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('mailbox_monitoring_settings', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('mailbox_monitoring_settings', ['tenant_id' => $other->id, 'enabled' => false]);
    }

    public function test_preview_and_confirm_update_now_are_costed_sanitized_and_idempotent(): void
    {
        Queue::fake();
        [$tenant, $client] = $this->tenant();
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
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_code' => 'LISTAR',
        ]);
    }

    public function test_detail_on_demand_requires_cost_preview_and_creates_one_run_per_isn(): void
    {
        Queue::fake();
        [$tenant, $client] = $this->tenant();
        $this->price('DETALHE', 100_000);
        $externalId = 'ISN-DETAIL-1';
        $message = MailboxMessage::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash($tenant->id, $client->id, $externalId),
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

    public function test_detail_preview_hides_foreign_message(): void
    {
        [$tenant] = $this->tenant();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $externalId = 'ISN-FOREIGN-DETAIL';
        $message = MailboxMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash(
                $otherTenant->id,
                $otherClient->id,
                $externalId,
            ),
            'source' => MailboxSource::CaixaPostal,
            'sensitivity_class' => 'FISCAL_RESTRICTED',
            'subject_preview' => 'Outro tenant',
            'triage_status' => MailboxTriageStatus::New,
            'has_body' => false,
            'attachment_count' => 0,
        ]);

        $this->getJson(
            '/api/v1/fiscal/mailbox/messages/'.$message->id.'/detail-preview',
        )->assertNotFound();
    }

    /** @return array{Tenant,Client} */
    private function tenant(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->for($tenant)->create();
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
        app(CurrentTenant::class)->resolve($user);

        return [$tenant, $client];
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
