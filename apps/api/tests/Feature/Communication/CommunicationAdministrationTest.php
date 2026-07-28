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
use App\Models\CommunicationLabel;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkDepartment;
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
        $this->assertSame(
            1,
            CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->count(),
        );

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
        self::assertSame([
            'id',
            'module_key',
            'submodule_key',
            'inbox_id',
            'inbox_name',
            'is_enabled',
            'send_day',
            'send_time',
            'timezone',
            'recipient_mode',
            'template_key',
            'template_version',
            'lock_version',
        ], array_keys($created->json('data')));
        $this->assertDatabaseCount('communication_events', 1);
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
        $index = $this->getJson('/api/v1/communication/automation-policies')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));
        self::assertSame([
            'supported_scopes',
            'inboxes',
            'tenant_enabled',
            'global_enabled',
        ], array_keys($index->json('meta')));
        self::assertSame([
            'id',
            'name',
            'status',
            'enabled',
        ], array_keys($index->json('meta.inboxes.0')));
        $this->assertDatabaseCount('communication_events', 1);

        $query = '?module_key=simples_mei&submodule_key=pgdasd';
        $recipients = $this->getJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query)
            ->assertOk()
            ->assertJsonCount(2, 'data.identities')
            ->assertJsonPath('data.identities.0.id', $first->id);
        self::assertSame([
            'client_id',
            'preference_id',
            'recipient_mode',
            'lock_version',
            'selected_identity_ids',
            'identities',
        ], array_keys($recipients->json('data')));
        self::assertSame([
            'id',
            'masked',
            'is_primary',
            'receives_automatic',
        ], array_keys($recipients->json('data.identities.0')));
        $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query, [
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [],
            'lock_version' => $preference->lock_version,
        ])->assertStatus(422);
        $updated = $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients', [
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [$second->id],
            'lock_version' => $preference->lock_version,
        ])->assertOk()
            ->assertJsonPath('data.recipient_mode', RecipientMode::Selected->value)
            ->assertJsonPath('data.selected_identity_ids.0', $second->id);
        self::assertSame(2, $updated->json('data.lock_version'));
        $this->putJson('/api/v1/communication/clients/'.$client->id.'/automation-recipients'.$query, [
            'recipient_mode' => RecipientMode::AllEligible->value,
            'identity_ids' => [$first->id, $second->id],
            'lock_version' => $preference->lock_version,
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    }

    public function test_automation_boundaries_reject_tenant_input_and_cross_tenant_resources(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);
        $inbox = $this->inbox($tenant);
        $foreignInbox = $this->inbox($foreignTenant);
        $foreignIdentity = $this->identity(
            $foreignTenant,
            $foreignClient,
            '+5511999997999',
            true,
        );
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
        $base = [
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'inbox_id' => $inbox->id,
            'is_enabled' => true,
            'send_day' => 20,
            'send_time' => '10:30',
            'timezone' => 'America/Sao_Paulo',
            'recipient_mode' => RecipientMode::Primary->value,
            'template_key' => 'pgdasd.document',
            'template_version' => '2',
            'lock_version' => 0,
        ];
        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/automation-policies?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->putJson('/api/v1/communication/automation-policies', [
            ...$base,
            'tenant_id' => $foreignTenant->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->putJson('/api/v1/communication/automation-policies', [
            ...$base,
            'inbox_id' => $foreignInbox->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('inbox_id');
        $this->assertDatabaseCount('communication_automation_policies', 0);

        $url = '/api/v1/communication/clients/'.$client->id.'/automation-recipients';
        $this->getJson($url.'?module_key=simples_mei&submodule_key=pgdasd&tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->putJson($url, [
            'tenant_id' => $foreignTenant->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [$foreignIdentity->id],
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->putJson($url, [
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [$foreignIdentity->id],
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonPath('code', 'ineligible_recipient');
        self::assertSame(1, $preference->refresh()->lock_version);
        $this->assertDatabaseCount('communication_preference_recipients', 0);

        $this->getJson(
            '/api/v1/communication/clients/'.$foreignClient->id
            .'/automation-recipients?module_key=simples_mei&submodule_key=pgdasd',
        )->assertNotFound();

        $this->authenticate($operator);
        $this->getJson('/api/v1/communication/automation-policies')->assertForbidden();
        $this->putJson($url, [
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'recipient_mode' => RecipientMode::Selected->value,
            'identity_ids' => [],
            'lock_version' => 1,
        ])->assertForbidden();
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

    public function test_inbox_boundaries_enforce_contracts_tenant_scope_members_and_versioning(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $operator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignAdmin = User::factory()
            ->forTenant($foreignTenant, TenantRole::TenantAdmin)
            ->create();
        $department = WorkDepartment::factory()->create(['tenant_id' => $tenant->id]);
        $foreignDepartment = WorkDepartment::factory()->create([
            'tenant_id' => $foreignTenant->id,
        ]);
        $foreignInbox = $this->inbox($foreignTenant);
        $operatorMembership = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $operator->id)
            ->firstOrFail();
        $foreignMembership = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $foreignTenant->id)
            ->where('user_id', $foreignAdmin->id)
            ->firstOrFail();
        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/inboxes?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->postJson('/api/v1/communication/inboxes', [
            'tenant_id' => $foreignTenant->id,
            'name' => 'Inbox indevida',
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->postJson('/api/v1/communication/inboxes', [
            'name' => 'Inbox com departamento estrangeiro',
            'work_department_id' => $foreignDepartment->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_department_id');

        $created = $this->postJson('/api/v1/communication/inboxes', [
            'name' => '  WhatsApp fiscal  ',
            'is_enabled' => true,
            'is_default' => true,
            'work_department_id' => $department->id,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'WhatsApp fiscal')
            ->assertJsonPath('data.work_department_id', $department->id)
            ->assertJsonPath('data.lock_version', 1);
        self::assertSame([
            'id',
            'name',
            'status',
            'address_masked',
            'is_enabled',
            'is_default',
            'work_department_id',
            'lock_version',
            'connected_at',
            'last_seen_at',
        ], array_keys($created->json('data')));
        $inboxId = (int) $created->json('data.id');
        $this->assertDatabaseCount('communication_events', 1);

        $index = $this->getJson('/api/v1/communication/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inboxId);
        self::assertSame([
            'global_enabled',
            'gateway_enabled',
            'tenant_enabled',
            'departments',
        ], array_keys($index->json('meta')));
        self::assertSame([
            'id',
            'name',
            'code',
            'color',
            'is_active',
        ], array_keys($index->json('meta.departments.0')));

        $url = '/api/v1/communication/inboxes/'.$inboxId;
        $this->patchJson($url, [
            'tenant_id' => $foreignTenant->id,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->patchJson($url, [
            'work_department_id' => $foreignDepartment->id,
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_department_id');
        $this->patchJson($url, [
            'is_enabled' => false,
            'lock_version' => 99,
        ])->assertConflict()->assertJsonPath('code', 'version_conflict');
        $this->assertDatabaseCount('communication_outbox_entries', 0);
        $this->assertDatabaseCount('communication_events', 1);

        $this->patchJson($url, [
            'is_enabled' => false,
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', InboxStatus::Disconnected->value)
            ->assertJsonPath('data.lock_version', 2);
        $this->assertDatabaseCount('communication_outbox_entries', 1);
        $this->assertDatabaseCount('communication_events', 2);

        $membersUrl = $url.'/members';
        $this->putJson($membersUrl, [
            'membership_ids' => [$operatorMembership->id],
        ])->assertOk()
            ->assertJsonPath('data.membership_ids.0', $operatorMembership->id);
        $this->putJson($membersUrl, [
            'membership_ids' => [$foreignMembership->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('membership_ids.0');
        $this->assertDatabaseHas('communication_inbox_members', [
            'inbox_id' => $inboxId,
            'tenant_membership_id' => $operatorMembership->id,
        ]);

        $this->putJson($membersUrl, [
            'tenant_id' => $foreignTenant->id,
            'membership_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->patchJson('/api/v1/communication/settings', [
            'tenant_id' => $foreignTenant->id,
            'enabled' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->postJson($url.'/session/connect?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->postJson($url.'/session/logout?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->deleteJson($url.'?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->patchJson('/api/v1/communication/inboxes/'.$foreignInbox->id, [
            'name' => 'Não autorizado',
            'lock_version' => $foreignInbox->lock_version,
        ])->assertNotFound();
        $this->putJson('/api/v1/communication/inboxes/'.$foreignInbox->id.'/members', [
            'membership_ids' => [],
        ])->assertNotFound();

        $this->authenticate($operator);
        $this->postJson('/api/v1/communication/inboxes', [
            'name' => 'Sem permissão',
        ])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
    }

    public function test_catalog_labels_and_capabilities_enforce_tenant_and_contracts(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $foreignLabel = CommunicationLabel::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Estrangeira',
            'color' => 'red',
        ]);
        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/labels?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->getJson('/api/v1/communication/outbound-capabilities?tenant_id='.$foreignTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
        $this->postJson('/api/v1/communication/labels', [
            'tenant_id' => $foreignTenant->id,
            'name' => 'Indevida',
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');

        $created = $this->postJson('/api/v1/communication/labels', [
            'name' => '  Urgente  ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Urgente')
            ->assertJsonPath('data.color', 'neutral');
        self::assertSame(['id', 'name', 'color'], array_keys($created->json('data')));

        $this->getJson('/api/v1/communication/labels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created->json('data.id'));
        $this->deleteJson('/api/v1/communication/labels/'.$foreignLabel->id)
            ->assertNotFound();

        $capabilities = $this->getJson('/api/v1/communication/outbound-capabilities')
            ->assertOk()
            ->assertJsonPath('data.enabled', true);
        self::assertEqualsCanonicalizing(
            ['private', 'no-store'],
            array_map(
                trim(...),
                explode(',', (string) $capabilities->headers->get('Cache-Control')),
            ),
        );
        self::assertSame([
            'enabled',
            'requires_permission',
            'kinds',
            'max_media_bytes',
        ], array_keys($capabilities->json('data')));

        $this->authenticate($viewer);
        $this->getJson('/api/v1/communication/labels')->assertOk();
        $this->postJson('/api/v1/communication/labels', [
            'name' => 'Sem permissão',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/communication/labels/'.$created->json('data.id'))
            ->assertForbidden();
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
