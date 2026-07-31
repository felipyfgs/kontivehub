<?php

namespace Tests\Feature;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class CommunicationModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_and_sensitive_values_are_casted(): void
    {
        $this->assertTrue(Schema::hasColumns('communication_inboxes', [
            'tenant_id',
            'session_id',
            'status',
            'is_enabled',
            'is_default',
        ]));
        $this->assertFalse(Schema::hasColumn('communication_inboxes', 'deleted_at'));
        $this->assertTrue(Schema::hasColumns('communication_messages', [
            'body_encrypted',
            'provider_message_id',
            'gateway_event_id',
        ]));
        $this->assertTrue(Schema::hasColumns('client_communication_dispatches', [
            'inbox_id',
            'identity_id',
            'message_id',
            'artifact_digest',
            'scheduled_at',
            'skipped_at',
        ]));

        $tenant = Tenant::factory()->create();
        $inbox = CommunicationInbox::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Geral',
            'session_id' => 'session-model-0001',
            'address_encrypted' => '+5511999991234',
            'address_hash' => hash('sha256', '+5511999991234'),
            'address_masked' => '+55•••••1234',
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
        $policy = CommunicationAutomationPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'inbox_id' => $inbox->id,
            'recipient_mode' => RecipientMode::AllEligible,
            'template_key' => 'pgdasd.das.available',
        ]);

        $this->assertSame(InboxStatus::Connected, $inbox->fresh()->status);
        $this->assertSame('+5511999991234', $inbox->fresh()->address_encrypted);
        $this->assertNotSame('+5511999991234', $inbox->fresh()->getRawOriginal('address_encrypted'));
        $this->assertSame(RecipientMode::AllEligible, $policy->fresh()->recipient_mode);
        $this->assertFalse($policy->fresh()->is_enabled);
    }

    public function test_inbox_status_contract_has_only_three_canonical_values(): void
    {
        $this->assertSame(
            ['DISCONNECTED', 'CONNECTING', 'CONNECTED'],
            array_map(static fn (InboxStatus $status): string => $status->value, InboxStatus::cases()),
        );

        $tenant = Tenant::factory()->create();
        $inbox = CommunicationInbox::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Sessão sem estado explícito',
            'session_id' => 'session-default-status-0001',
        ]);

        $this->assertSame(InboxStatus::Disconnected, $inbox->fresh()->status);
    }

    public function test_identity_is_unique_inside_tenant_but_can_repeat_in_another_tenant(): void
    {
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $hash = hash('sha256', '+5511999991234');

        $this->createIdentity($firstTenant, $hash);
        $this->createIdentity($secondTenant, $hash);

        $this->expectException(QueryException::class);
        $this->createIdentity($firstTenant, $hash);
    }

    public function test_only_one_default_inbox_and_one_active_conversation_are_allowed(): void
    {
        $tenant = Tenant::factory()->create();
        $inbox = $this->createInbox($tenant, 'first-session', true);
        $identity = $this->createIdentity($tenant, hash('sha256', '+5511999990001'));

        CommunicationConversation::query()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Resolved,
        ]);
        CommunicationConversation::query()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
        ]);

        try {
            CommunicationConversation::query()->create([
                'tenant_id' => $tenant->id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identity->id,
                'status' => ConversationStatus::Pending,
            ]);
            $this->fail('A segunda conversa ativa deveria violar a constraint.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $this->createInbox($tenant, 'second-session', true);
    }

    public function test_global_scope_isolates_inboxes_by_current_tenant(): void
    {
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $first = $this->createInbox($firstTenant, 'scope-session-1');
        $this->createInbox($secondTenant, 'scope-session-2');
        $membership = TenantMembership::factory()->create(['tenant_id' => $firstTenant->id]);

        app(CurrentTenant::class)->bind($membership->user, $membership->load('tenant'));

        $this->assertSame([$first->id], CommunicationInbox::query()->pluck('id')->all());
    }

    public function test_communication_event_is_append_only(): void
    {
        $tenant = Tenant::factory()->create();
        $event = CommunicationEvent::query()->create([
            'tenant_id' => $tenant->id,
            'type' => 'CONVERSATION_CREATED',
            'payload_digest' => hash('sha256', '{}'),
            'payload' => ['status' => 'OPEN'],
            'occurred_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->update(['type' => 'CHANGED']);
    }

    private function createInbox(Tenant $tenant, string $sessionId, bool $default = false): CommunicationInbox
    {
        return CommunicationInbox::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox '.$sessionId,
            'session_id' => $sessionId,
            'status' => InboxStatus::Disconnected,
            'is_enabled' => false,
            'is_default' => $default,
        ]);
    }

    private function createIdentity(Tenant $tenant, string $hash): CommunicationIdentity
    {
        $contact = CommunicationContact::query()->create([
            'tenant_id' => $tenant->id,
            'is_provisional' => true,
            'is_active' => true,
        ]);

        return CommunicationIdentity::query()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => '+5511999991234',
            'address_hash' => $hash,
            'address_masked' => '+55•••••1234',
            'is_active' => true,
        ]);
    }
}
