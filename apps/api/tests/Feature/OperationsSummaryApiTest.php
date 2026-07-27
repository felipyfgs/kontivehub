<?php

namespace Tests\Feature;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\OutboxStatus;
use App\Enums\CommunicationChannel;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\FiscalMonitoringRun;
use App\Models\MeiAutomationAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OperationsSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
        ]);
    }

    public function test_summary_returns_typed_contract_keys_without_forbidden_fields(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $this->authenticate($user);

        $response = $this->getJson('/api/v1/operations/summary');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);

        foreach ([
            'clients',
            'establishments',
            'notes',
            'exports_ready',
            'exports_pending',
            'sync_due',
            'sync_blocked',
            'sync_failures_24h',
            'credentials_expiring_30d',
            'inbox_critical',
            'inbox_high',
            'inbox_total',
            'backup',
            'svrs_nfce',
            'serpro_authorization',
            'proxy_powers',
            'modules',
            'fiscal_pending',
            'fiscal_coverage',
            'usage',
            'subscription',
            'blocks',
            'uncertain_results',
            'platform_health',
            'guides_due_7d',
            'communication',
            'mei_automation',
            'fiscal_runs',
            'generated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "Missing key {$key}");
        }

        $health = $data['platform_health'];
        $this->assertIsArray($health);
        $this->assertArrayHasKey('available', $health);
        $this->assertArrayHasKey('kill_switch', $health);
        $this->assertArrayNotHasKey('fingerprint', $health);
        $this->assertArrayNotHasKey('has_pfx', $health);
        $this->assertArrayNotHasKey('consumer_key', $health);
        $this->assertArrayNotHasKey('contracts', $health);
        $this->assertArrayNotHasKey('global_budget', $health);

        $this->assertTrue($data['communication']['available']);
        $this->assertTrue($data['communication']['global_enabled']);
        $this->assertTrue($data['communication']['gateway_enabled']);
        $this->assertTrue($data['communication']['tenant_enabled']);
        $this->assertTrue($data['mei_automation']['available']);
        $this->assertTrue($data['fiscal_runs']['available']);

        if (($data['usage']['available'] ?? false) === true) {
            $this->assertSame('/conta/consumo', $data['usage']['deep_link'] ?? null);
        }
    }

    public function test_summary_communication_and_light_counters_are_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create(['communication_enabled' => true]);
        $tenantB = Tenant::factory()->create(['communication_enabled' => true]);
        $userA = User::factory()->forTenant($tenantA, TenantRole::TenantAdmin)->create();

        $this->seedCommunicationRollup($tenantA, InboxStatus::Connected, ConversationStatus::Open, OutboxStatus::Dead);
        $this->seedCommunicationRollup($tenantA, InboxStatus::Connecting, ConversationStatus::Pending, OutboxStatus::Retry);
        $this->seedCommunicationRollup($tenantB, InboxStatus::Connected, ConversationStatus::Open, OutboxStatus::Dead);

        $clientA = Client::factory()->forTenant($tenantA)->create();
        $clientB = Client::factory()->forTenant($tenantB)->create();
        MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'client_id' => $clientA->id,
            'operation_key' => 'pgmei.dividaativa',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Failed,
            'idempotency_key' => 'mei-a-'.Str::ulid(),
            'request_fingerprint' => str_repeat('a', 64),
        ]);
        MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'client_id' => $clientB->id,
            'operation_key' => 'pgmei.dividaativa',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Failed,
            'idempotency_key' => 'mei-b-'.Str::ulid(),
            'request_fingerprint' => str_repeat('b', 64),
        ]);

        FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantA->id,
            'client_id' => $clientA->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgdasd.monitor',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'run-a-'.Str::ulid(),
            'status' => FiscalRunStatus::Failed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
        FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantB->id,
            'client_id' => $clientB->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgdasd.monitor',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'run-b-'.Str::ulid(),
            'status' => FiscalRunStatus::Failed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        $this->authenticate($userA);
        $data = $this->getJson('/api/v1/operations/summary')->assertOk()->json('data');

        $this->assertSame(1, $data['communication']['inboxes_by_status'][InboxStatus::Connected->value]);
        $this->assertSame(1, $data['communication']['inboxes_by_status'][InboxStatus::Connecting->value]);
        $this->assertSame(1, $data['communication']['conversations_open']);
        $this->assertSame(1, $data['communication']['conversations_pending']);
        $this->assertSame(1, $data['communication']['outbox_dead']);
        $this->assertSame(1, $data['communication']['outbox_retry']);
        $this->assertSame(1, $data['mei_automation']['failed_24h']);
        $this->assertSame(1, $data['fiscal_runs']['failed_24h']);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function seedCommunicationRollup(
        Tenant $tenant,
        InboxStatus $inboxStatus,
        ConversationStatus $conversationStatus,
        OutboxStatus $outboxStatus,
    ): void {
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox '.$inboxStatus->value,
            'session_id' => 'session-'.Str::ulid(),
            'status' => $inboxStatus,
            'is_enabled' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato',
            'is_active' => true,
        ]);
        $address = '+5511'.random_int(100000000, 999999999);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => $conversationStatus,
            'last_message_at' => now(),
        ]);
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'direction' => MessageDirection::Outbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::Human,
            'status' => MessageStatus::Queued,
            'body_encrypted' => 'teste',
            'provider_message_id' => 'provider-'.Str::ulid(),
            'content_digest' => hash('sha256', (string) Str::ulid()),
            'occurred_at' => now(),
        ]);
        $payload = ['to' => $address, 'kind' => 'TEXT', 'text' => 'teste'];
        CommunicationOutboxEntry::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'message_id' => $message->id,
            'command_id' => 'command-'.Str::ulid(),
            'session_id' => $inbox->session_id,
            'type' => GatewayCommandType::SendMessage,
            'payload_encrypted' => $payload,
            'payload_digest' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'status' => $outboxStatus,
            'available_at' => now()->subSecond(),
        ]);
    }
}
