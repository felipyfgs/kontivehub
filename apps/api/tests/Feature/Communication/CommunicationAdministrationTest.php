<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\RecipientMode;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationInbox;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([CommunicationEventCommitted::class]);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
        ]);
    }

    public function test_contacts_support_multiple_client_links_but_remain_isolated_by_tenant(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();
        $clientA = Client::factory()->create(['tenant_id' => $tenant->id]);
        $clientB = Client::factory()->create(['tenant_id' => $tenant->id]);
        $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);

        $this->authenticate($admin);
        $created = $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Contador responsável',
            'phone' => '(11) 99999-5555',
            'client_id' => $clientA->id,
            'is_primary' => true,
        ])->assertCreated()
            ->assertJsonPath('data.identities.0.links.0.client_id', $clientA->id);
        $contactId = (int) $created->json('data.id');
        $identityId = (int) $created->json('data.identities.0.id');

        $this->postJson('/api/v1/communication/identities/'.$identityId.'/links', [
            'client_id' => $clientB->id,
            'receives_automatic' => true,
        ])->assertCreated();
        $this->assertDatabaseCount('communication_identity_links', 2);
        $this->getJson('/api/v1/communication/contacts/'.$contactId)
            ->assertOk()
            ->assertJsonCount(2, 'data.identities.0.links');
        $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Duplicado',
            'phone' => '+55 11 99999-5555',
        ])->assertStatus(409)->assertJsonPath('code', 'identity_conflict');

        $this->authenticate($operator);
        $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Sem gerência',
            'phone' => '+5511999996666',
        ])->assertForbidden();

        $this->authenticate($foreignAdmin);
        $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Mesmo número, outro escritório',
            'phone' => '+5511999995555',
            'client_id' => $foreignClient->id,
        ])->assertCreated();
        $this->assertSame(2, CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('address_hash', hash('sha256', '+5511999995555'))->count());
        $this->getJson('/api/v1/communication/contacts/'.$contactId)->assertNotFound();
    }

    public function test_policy_and_selected_recipients_are_explicit_versioned_and_fail_closed(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $inbox = $this->inbox($tenant);
        $first = $this->identity($tenant, $client, '+5511999997001', true);
        $second = $this->identity($tenant, $client, '+5511999997002', false);
        $preference = ClientCommunicationPreference::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'automatic_requested' => true,
            'whatsapp_enabled' => true,
            'recipient_mode' => RecipientMode::Primary,
            'lock_version' => 1,
        ]);
        $this->authenticate($admin);

        $base = [
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'inbox_id' => null,
            'is_enabled' => true,
            'send_day' => 20,
            'send_time' => '10:30',
            'timezone' => 'America/Sao_Paulo',
            'recipient_mode' => RecipientMode::Selected->value,
            'template_key' => 'pgdasd.document',
            'template_version' => '2',
            'lock_version' => 0,
        ];
        $this->putJson('/api/v1/communication/automation-policies', $base)->assertStatus(422);
        $created = $this->putJson('/api/v1/communication/automation-policies', [
            ...$base,
            'inbox_id' => $inbox->id,
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.recipient_mode', RecipientMode::Selected->value);
        $this->putJson('/api/v1/communication/automation-policies', [
            ...$base,
            'inbox_id' => $inbox->id,
            'send_day' => 21,
            'lock_version' => 0,
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        $this->putJson('/api/v1/communication/automation-policies', [
            ...$base,
            'module_key' => 'defis',
            'inbox_id' => $inbox->id,
        ])->assertStatus(422);
        $this->getJson('/api/v1/communication/automation-policies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));

        $query = '?module_key=simples_mei&submodule_key=pgdasd';
        $this->getJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query)
            ->assertOk()
            ->assertJsonCount(2, 'data.identities')
            ->assertJsonPath('data.identities.0.id', $first->id);
        $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query, [
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [],
            'lock_version' => $preference->lock_version,
        ])->assertStatus(422);
        $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query, [
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [$second->id],
            'lock_version' => $preference->lock_version,
        ])->assertOk()
            ->assertJsonPath('data.recipient_mode', RecipientMode::Selected->value)
            ->assertJsonPath('data.selected_identity_ids.0', $second->id);
        $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query, [
            'recipient_mode' => RecipientMode::AllEligible->value,
            'identity_ids' => [$first->id, $second->id],
            'lock_version' => $preference->lock_version,
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    }

    public function test_pairing_is_durable_and_switches_refuse_commands_when_disabled(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $inbox->forceFill(['status' => InboxStatus::Disconnected])->save();
        $foreignInbox = $this->inbox($foreignTenant);
        $this->authenticate($admin);

        $first = $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/connect')
            ->assertStatus(202)
            ->assertJsonPath('data.event', 'pending')
            ->assertJsonCount(1, 'data.commands');
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/connect')
            ->assertStatus(202)
            ->assertJsonPath('data.event', 'pending')
            ->assertJsonPath('data.expires_at', $first->json('data.expires_at'))
            ->assertJsonPath('data.commands', $first->json('data.commands'));
        $this->assertDatabaseCount('communication_outbox_entries', 1);
        $this->assertSame(InboxStatus::Connecting, $inbox->refresh()->status);
        $this->postJson('/api/v1/communication/inboxes/'.$foreignInbox->id.'/session/connect')->assertNotFound();
        $this->assertDatabaseCount('communication_outbox_entries', 1);

        config(['communication.enabled' => false]);
        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/connect')
            ->assertStatus(503)
            ->assertJsonPath('code', 'COMMUNICATION_DISABLED');
        $this->assertDatabaseCount('communication_outbox_entries', 1);
    }

    public function test_logout_is_idempotent_preserves_history_and_connect_starts_a_new_pairing(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $inbox = $this->inbox($tenant);
        $identity = $this->identity($tenant, $client, '+5511999997111', true);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        $this->authenticate($admin);
        $url = '/api/v1/communication/inboxes/'.$inbox->id.'/session/logout';

        $this->postJson($url)
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::LogoutSession->value)
            ->assertJsonPath('data.status', InboxStatus::Disconnected->value);
        $this->postJson($url)
            ->assertStatus(202)
            ->assertJsonPath('data.command_id', null);

        $this->assertDatabaseCount('communication_outbox_entries', 1);
        $this->assertDatabaseHas('communication_outbox_entries', [
            'inbox_id' => $inbox->id,
            'type' => GatewayCommandType::LogoutSession->value,
        ]);
        $this->assertDatabaseHas('communication_conversations', ['id' => $conversation->id]);
        $this->assertTrue($inbox->refresh()->is_enabled);
        $this->assertSame(InboxStatus::Disconnected, $inbox->status);
        $this->assertNotNull($inbox->revoked_at);

        $this->postJson('/api/v1/communication/inboxes/'.$inbox->id.'/session/connect')
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::ConnectSession->value)
            ->assertJsonPath('data.status', InboxStatus::Connecting->value);
        $this->assertNull($inbox->refresh()->revoked_at);
        $this->assertSame(InboxStatus::Connecting, $inbox->status);
        $this->assertDatabaseCount('communication_outbox_entries', 2);
    }

    public function test_deleting_one_session_logs_it_out_archives_it_and_preserves_other_sessions_and_history(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $deletedInbox = $this->inbox($tenant);
        $remainingInbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WhatsApp financeiro',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => false,
        ]);
        $identity = $this->identity($tenant, $client, '+5511999997222', true);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $deletedInbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);
        $this->authenticate($admin);

        $this->deleteJson('/api/v1/communication/inboxes/'.$deletedInbox->id)
            ->assertStatus(202)
            ->assertJsonPath('data.type', GatewayCommandType::LogoutSession->value)
            ->assertJsonPath('data.status', InboxStatus::Disconnected->value)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('communication_inboxes', ['id' => $deletedInbox->id]);
        $this->assertDatabaseMissing('communication_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('communication_events', [
            'type' => 'INBOX_DELETED',
        ]);
        $this->assertDatabaseHas('communication_outbox_entries', [
            'session_id' => $deletedInbox->session_id,
            'type' => GatewayCommandType::LogoutSession->value,
        ]);

        $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('session_id', $deletedInbox->session_id)
            ->firstOrFail();
        $this->assertNull($entry->inbox_id);
        $this->assertSame(InboxStatus::Connected, $remainingInbox->refresh()->status);
        $this->assertTrue($remainingInbox->is_default);

        $this->getJson('/api/v1/communication/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $remainingInbox->id);
        $this->deleteJson('/api/v1/communication/inboxes/'.$deletedInbox->id)->assertNotFound();
    }

    public function test_archived_inbox_name_can_be_reused_but_an_active_duplicate_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $archivedInbox = $this->inbox($tenant);
        $this->authenticate($admin);

        $this->postJson('/api/v1/communication/inboxes', [
            'name' => '  WhatsApp geral  ',
            'is_enabled' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->deleteJson('/api/v1/communication/inboxes/'.$archivedInbox->id)
            ->assertStatus(202);

        $created = $this->postJson('/api/v1/communication/inboxes', [
            'name' => '  WhatsApp geral  ',
            'is_enabled' => true,
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'WhatsApp geral')
            ->assertJsonPath('data.is_default', true);

        $this->assertNotSame($archivedInbox->id, (int) $created->json('data.id'));
        $this->assertDatabaseMissing('communication_inboxes', ['id' => $archivedInbox->id]);
        $this->assertSame(1, CommunicationInbox::query()->withoutGlobalScopes()->count());
    }

    public function test_disabling_tenant_disconnects_each_session_without_logging_out_or_reconnecting_on_enable(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $first = $this->inbox($tenant);
        $second = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WhatsApp financeiro',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => false,
        ]);
        $foreign = $this->inbox($foreignTenant);
        $this->authenticate($admin);

        $this->patchJson('/api/v1/communication/settings', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertSame(InboxStatus::Disconnected, $first->refresh()->status);
        $this->assertSame(InboxStatus::Disconnected, $second->refresh()->status);
        $this->assertSame(InboxStatus::Connected, $foreign->refresh()->status);
        $this->assertDatabaseCount('communication_outbox_entries', 2);
        $this->assertDatabaseMissing('communication_outbox_entries', [
            'type' => GatewayCommandType::LogoutSession->value,
        ]);

        $this->patchJson('/api/v1/communication/settings', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        $this->assertDatabaseCount('communication_outbox_entries', 2);
        $this->assertSame(InboxStatus::Disconnected, $first->refresh()->status);
        $this->assertSame(InboxStatus::Disconnected, $second->refresh()->status);
    }

    public function test_disabling_an_inbox_disconnects_it_without_removing_credentials_by_logout(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant);
        $this->authenticate($admin);

        $this->patchJson('/api/v1/communication/inboxes/'.$inbox->id, [
            'is_enabled' => false,
            'lock_version' => $inbox->refresh()->lock_version,
        ])->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.status', InboxStatus::Disconnected->value);

        $this->assertDatabaseHas('communication_outbox_entries', [
            'inbox_id' => $inbox->id,
            'type' => GatewayCommandType::DisconnectSession->value,
        ]);
        $this->assertDatabaseMissing('communication_outbox_entries', [
            'inbox_id' => $inbox->id,
            'type' => GatewayCommandType::LogoutSession->value,
        ]);
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'WhatsApp geral',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'is_default' => true,
        ]);
    }

    private function identity(Tenant $tenant, Client $client, string $address, bool $primary): CommunicationIdentity
    {
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Contato '.substr($address, -4),
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => 'WHATSAPP',
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '***'.substr($address, -4),
            'is_active' => true,
        ]);
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $identity->id,
            'client_id' => $client->id,
            'is_primary' => $primary,
            'receives_automatic' => true,
        ]);

        return $identity;
    }
}
