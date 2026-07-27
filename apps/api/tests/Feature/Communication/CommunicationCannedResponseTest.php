<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationCannedResponseTest extends TestCase
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

    public function test_manage_quick_replies_permission_is_in_admin_effective_set(): void
    {
        $this->assertSame('communication.manage_quick_replies', TenantPermission::CommunicationManageQuickReplies->value);
        $this->assertSame('Gerenciar respostas rápidas de comunicação', TenantPermission::CommunicationManageQuickReplies->label());
        $this->assertContains(
            TenantPermission::CommunicationManageQuickReplies->value,
            TenantPermission::orderedValues(),
        );
    }

    public function test_composer_lists_only_active_and_manage_lists_inactive_with_pagination(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()->forTenant($foreign, TenantRole::TenantAdmin)->create();

        $active = $this->canned($tenant, 'saudacao', 'Saudação', 'Olá {{contato.nome}}', active: true);
        $inactive = $this->canned($tenant, 'encerrar', 'Encerrar', 'Até logo', active: false);
        $this->canned($foreign, 'outro', 'Outro', 'X', active: true);

        $this->authenticate($admin);

        $composer = $this->getJson('/api/v1/communication/canned-responses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.body', 'Olá {{contato.nome}}')
            ->assertJsonPath('data.0.lock_version', 1)
            ->json();
        $this->assertArrayNotHasKey('meta', $composer);

        $this->getJson('/api/v1/communication/canned-responses?manage=1&is_active=false&q=encer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/communication/canned-responses?manage=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->authenticate($foreignAdmin);
        $this->getJson('/api/v1/communication/canned-responses?manage=1')
            ->assertOk()
            ->assertJsonMissing(['id' => $active->id]);
        $this->putJson('/api/v1/communication/canned-responses/'.$active->id, [
            'title' => 'Hack',
            'shortcut' => 'hack',
            'body' => 'x',
            'lock_version' => 1,
        ])->assertNotFound();
    }

    public function test_mutations_require_manage_quick_replies_not_inboxes_or_contacts(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inboxManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $contactManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $quickManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();

        $this->assignProfile($viewer, $tenant, [TenantPermission::CommunicationView]);
        $this->assignProfile($inboxManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageInboxes,
        ]);
        $this->assignProfile($contactManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageContacts,
        ]);
        $this->assignProfile($quickManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageQuickReplies,
        ]);

        $item = $this->canned($tenant, 'base', 'Base', 'Corpo', active: true);

        $mutations = [
            fn () => $this->postJson('/api/v1/communication/canned-responses', [
                'title' => 'Novo',
                'shortcut' => 'novo',
                'body' => 'Texto',
            ]),
            fn () => $this->putJson('/api/v1/communication/canned-responses/'.$item->id, [
                'title' => 'Editado',
                'shortcut' => 'base',
                'body' => 'Novo corpo',
                'lock_version' => 1,
            ]),
            fn () => $this->postJson('/api/v1/communication/canned-responses/'.$item->id.'/duplicate', [
                'shortcut' => 'base-copia',
            ]),
            fn () => $this->postJson('/api/v1/communication/canned-responses/'.$item->id.'/deactivate'),
            fn () => $this->getJson('/api/v1/communication/canned-responses?manage=1&is_active=false'),
        ];

        foreach ([$viewer, $inboxManager, $contactManager] as $denied) {
            $this->authenticate($denied);
            foreach ($mutations as $mutation) {
                $mutation()->assertForbidden();
            }
        }

        $this->authenticate($viewer);
        $this->getJson('/api/v1/communication/canned-responses')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticate($quickManager);
        $this->postJson('/api/v1/communication/canned-responses', [
            'title' => 'Permitido',
            'shortcut' => 'permitido',
            'body' => 'Olá {{contato.nome}}',
        ])->assertCreated()
            ->assertJsonPath('data.shortcut', 'permitido')
            ->assertJsonPath('data.lock_version', 1);

        $this->putJson('/api/v1/communication/canned-responses/'.$item->id, [
            'title' => 'Atualizado',
            'shortcut' => 'base',
            'body' => 'Corpo atualizado',
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Atualizado')
            ->assertJsonPath('data.lock_version', 2);

        $this->postJson('/api/v1/communication/canned-responses/'.$item->id.'/duplicate', [
            'shortcut' => 'base-2',
        ])->assertCreated()
            ->assertJsonPath('data.shortcut', 'base-2')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.body', 'Corpo atualizado');

        $this->postJson('/api/v1/communication/canned-responses/'.$item->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/communication/canned-responses')
            ->assertOk()
            ->assertJsonMissing(['id' => $item->id]);
    }

    public function test_shortcut_uniqueness_version_conflict_and_audit_without_body(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $first = $this->postJson('/api/v1/communication/canned-responses', [
            'title' => 'Um',
            'shortcut' => 'atalho',
            'body' => 'Segredo não deve ir ao evento',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/communication/canned-responses', [
            'title' => 'Dois',
            'shortcut' => 'atalho',
            'body' => 'Outro',
        ])->assertStatus(409)->assertJsonPath('code', 'shortcut_conflict');

        $row = CommunicationCannedResponse::query()->findOrFail($first['id']);
        $raw = $row->getAttributes()['body_encrypted'] ?? null;
        $this->assertIsString($raw);
        $this->assertNotSame('Segredo não deve ir ao evento', $raw);

        $this->putJson('/api/v1/communication/canned-responses/'.$first['id'], [
            'title' => 'Um',
            'shortcut' => 'atalho',
            'body' => 'Alterado',
            'lock_version' => 99,
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');

        $this->assertSame('Segredo não deve ir ao evento', $row->fresh()->body_encrypted);

        $this->postJson('/api/v1/communication/canned-responses', [
            'title' => 'Ruim',
            'shortcut' => 'pii',
            'body' => 'Tel {{contato.telefone}} doc {{cliente.cpf}}',
        ])->assertStatus(422)->assertJsonValidationErrors(['body']);

        $events = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'CANNED_RESPONSE_CREATED')
            ->get();
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $this->assertArrayNotHasKey('body', $payload);
            $encoded = json_encode($payload);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('Segredo', $encoded);
        }
    }

    public function test_render_resolves_allowlist_and_rejects_cross_tenant(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create([
            'name' => 'Atendente Silva',
        ]);
        $foreignAdmin = User::factory()->forTenant($foreign, TenantRole::TenantAdmin)->create();

        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox Principal',
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Maria Contato',
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => '+5511999990000',
            'address_hash' => hash('sha256', '+5511999990000'),
            'address_masked' => '***0000',
            'is_active' => true,
        ]);
        $client = Client::factory()->create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Cliente Beta',
        ]);
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $identity->id,
            'client_id' => $client->id,
            'client_contact_id' => null,
            'is_primary' => true,
            'receives_automatic' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);

        $canned = $this->canned(
            $tenant,
            'ctx',
            'Contextual',
            'Oi {{contato.nome}} / {{cliente.nome}} / {{atendente.nome}} / {{inbox.nome}}',
            active: true,
        );
        $inactive = $this->canned($tenant, 'off', 'Off', 'x', active: false);
        $foreignCanned = $this->canned($foreign, 'fx', 'Foreign', 'Oi {{contato.nome}}', active: true);
        $foreignConversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id,
            'inbox_id' => CommunicationInbox::query()->withoutGlobalScopes()->create([
                'tenant_id' => $foreign->id,
                'name' => 'Foreign Inbox',
                'session_id' => 'session-'.Str::ulid(),
                'status' => InboxStatus::Connected,
                'is_enabled' => true,
            ])->id,
            'identity_id' => CommunicationIdentity::query()->withoutGlobalScopes()->create([
                'tenant_id' => $foreign->id,
                'contact_id' => CommunicationContact::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $foreign->id,
                    'name' => 'Outro',
                    'is_active' => true,
                ])->id,
                'channel' => CommunicationChannel::Whatsapp,
                'address_encrypted' => '+5511888880000',
                'address_hash' => hash('sha256', '+5511888880000'),
                'address_masked' => '***0000',
                'is_active' => true,
            ])->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => now(),
        ]);

        $this->authenticate($admin);
        $this->postJson('/api/v1/communication/canned-responses/'.$canned->id.'/render', [
            'conversation_id' => $conversation->id,
        ])->assertOk()
            ->assertJsonPath(
                'data.body',
                'Oi Maria Contato / Cliente Beta / Atendente Silva / Inbox Principal',
            );

        $this->postJson('/api/v1/communication/canned-responses/'.$inactive->id.'/render', [
            'conversation_id' => $conversation->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/communication/canned-responses/'.$canned->id.'/render', [
            'conversation_id' => $foreignConversation->id,
        ])->assertNotFound();

        $this->authenticate($foreignAdmin);
        $this->postJson('/api/v1/communication/canned-responses/'.$canned->id.'/render', [
            'conversation_id' => $conversation->id,
        ])->assertNotFound();
        $this->postJson('/api/v1/communication/canned-responses/'.$foreignCanned->id.'/render', [
            'conversation_id' => $conversation->id,
        ])->assertNotFound();
    }

    /** @param list<TenantPermission> $permissions */
    private function assignProfile(User $user, Tenant $tenant, array $permissions): void
    {
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys($permissions);
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $membership->forceFill([
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => (int) $membership->authorization_version + 1,
        ])->save();
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function canned(
        Tenant $tenant,
        string $shortcut,
        string $title,
        string $body,
        bool $active,
    ): CommunicationCannedResponse {
        return CommunicationCannedResponse::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'title' => $title,
            'shortcut' => $shortcut,
            'body_encrypted' => $body,
            'is_active' => $active,
            'lock_version' => 1,
        ]);
    }
}
