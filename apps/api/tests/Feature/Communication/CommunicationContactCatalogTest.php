<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationContactCatalogTest extends TestCase
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

    public function test_index_filters_sort_and_includes_client_names_without_clear_address(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()->forTenant($foreignTenant, TenantRole::TenantAdmin)->create();

        $client = Client::factory()->create([
            'tenant_id' => $tenant->id,
            'display_name' => 'Cliente Alpha',
            'legal_name' => 'Alpha Ltda',
        ]);
        $clientContact = ClientContact::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'name' => 'Maria Contato',
        ]);

        $linked = $this->contact($tenant, 'Zebra Linked', provisional: false, active: true);
        $linkedIdentity = $this->identity($tenant, $linked, '+5511999900001');
        CommunicationIdentityLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'identity_id' => $linkedIdentity->id,
            'client_id' => $client->id,
            'client_contact_id' => $clientContact->id,
            'is_primary' => true,
            'receives_automatic' => true,
        ]);

        $provisional = $this->contact($tenant, null, provisional: true, active: true);
        $this->identity($tenant, $provisional, '+5511999900002');

        $inactive = $this->contact($tenant, 'Inativo', provisional: false, active: false);
        $this->identity($tenant, $inactive, '+5511999900003');

        $unlinked = $this->contact($tenant, 'Alpha Unlinked', provisional: false, active: true);
        $this->identity($tenant, $unlinked, '+5511999900004');

        $foreign = $this->contact($foreignTenant, 'Estrangeiro', provisional: false, active: true);
        $this->identity($foreignTenant, $foreign, '+5511999900099');

        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/contacts?is_provisional=true&linked=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $provisional->id)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/communication/contacts?linked=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $linked->id)
            ->assertJsonPath('data.0.identities.0.links.0.client_name', 'Cliente Alpha')
            ->assertJsonPath('data.0.identities.0.links.0.client_contact_name', 'Maria Contato');

        $payload = $this->getJson('/api/v1/communication/contacts/'.$linked->id)
            ->assertOk()
            ->assertJsonPath('data.identities.0.links.0.client_name', 'Cliente Alpha')
            ->assertJsonPath('data.identities.0.links.0.client_contact_name', 'Maria Contato')
            ->json('data');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('address_encrypted', $payload['identities'][0]);
        $this->assertArrayNotHasKey('address', $payload['identities'][0]);
        $this->assertSame($linkedIdentity->address_masked, $payload['identities'][0]['address_masked']);

        $this->getJson('/api/v1/communication/contacts?is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);

        $sorted = $this->getJson('/api/v1/communication/contacts?sort=name&sort_direction=asc')
            ->assertOk()
            ->json('data');
        $this->assertSame(
            ['Alpha Unlinked', 'Zebra Linked'],
            array_values(array_filter(array_column($sorted, 'name'))),
        );

        $this->getJson('/api/v1/communication/contacts?sort=not_a_column')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->authenticate($foreignAdmin);
        $this->getJson('/api/v1/communication/contacts/'.$linked->id)->assertNotFound();
        $this->getJson('/api/v1/communication/contacts')
            ->assertOk()
            ->assertJsonMissing(['id' => $linked->id]);
    }

    public function test_contact_pagination_preserves_its_compact_v1_contract(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $first = $this->contact($tenant, 'Alpha', provisional: false, active: true);
        $this->contact($tenant, 'Beta', provisional: false, active: true);

        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/contacts?sort=name&per_page=1&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $first->id,
                    'name' => 'Alpha',
                    'is_provisional' => false,
                    'is_active' => true,
                    'profile_picture_url' => null,
                    'profile_picture_state' => 'UNKNOWN',
                    'identities' => [],
                    'purged_at' => null,
                ]],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 2,
                    'total' => 2,
                ],
            ]);
    }

    public function test_inbox_context_resolves_filters_searches_and_sorts_names_without_cross_inbox_leak(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $firstInbox = $this->inbox($tenant, 'Primeira');
        $secondInbox = $this->inbox($tenant, 'Segunda');
        $foreignInbox = $this->inbox($foreignTenant, 'Estrangeira');

        $first = $this->contact($tenant, null, provisional: true, active: true);
        $firstIdentity = $this->identity($tenant, $first, '+5511999900201');
        $this->conversation($tenant, $firstInbox, $firstIdentity, now()->subMinute());
        $this->conversation($tenant, $secondInbox, $firstIdentity, now());
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $firstInbox->id,
            'identity_id' => $firstIdentity->id,
            'business_name' => 'Empresa da primeira inbox',
        ]);
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $secondInbox->id,
            'identity_id' => $firstIdentity->id,
            'address_book_full_name' => 'Agenda da segunda inbox',
        ]);

        $second = $this->contact($tenant, null, provisional: true, active: true);
        $secondIdentity = $this->identity($tenant, $second, '+5511999900202');
        $this->conversation($tenant, $firstInbox, $secondIdentity, now()->subSecond());
        CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $firstInbox->id,
            'identity_id' => $secondIdentity->id,
            'push_name' => 'Alpha observado',
        ]);

        $outside = $this->contact($tenant, 'Sem conversa nesta inbox', provisional: false, active: true);
        $this->identity($tenant, $outside, '+5511999900203');

        $this->authenticate($admin);

        $firstResponse = $this->getJson('/api/v1/communication/contacts?inbox_id='.$firstInbox->id.'&sort=name')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.display_name', 'Alpha observado')
            ->assertJsonPath('data.0.display_name_source', 'WHATSAPP_PUSH_NAME')
            ->assertJsonPath('data.0.display_name_state', 'OBSERVED')
            ->assertJsonPath('data.0.display_name_inbox_id', $firstInbox->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.display_name', 'Empresa da primeira inbox')
            ->assertJsonPath('data.1.display_name_source', 'WHATSAPP_BUSINESS');
        $this->assertNotContains($outside->id, $firstResponse->json('data.*.id'));

        $this->getJson('/api/v1/communication/contacts?inbox_id='.$firstInbox->id.'&q=empresa%20da%20primeira')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first->id);
        $this->postJson('/api/v1/communication/contacts/search', [
            'q' => '+5511999900201',
            'inbox_id' => $firstInbox->id,
        ])->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first->id);

        $this->getJson('/api/v1/communication/contacts?inbox_id='.$secondInbox->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.display_name', 'Agenda da segunda inbox')
            ->assertJsonPath('data.0.display_name_source', 'WHATSAPP_ADDRESS_BOOK')
            ->assertJsonPath('data.0.profile_picture_inbox_id', $secondInbox->id);

        $this->getJson('/api/v1/communication/contacts/'.$first->id.'?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Empresa da primeira inbox')
            ->assertJsonPath('data.display_name_inbox_id', $firstInbox->id);

        $first->forceFill(['name' => 'Nome manual', 'is_provisional' => false])->save();
        $this->getJson('/api/v1/communication/contacts?inbox_id='.$firstInbox->id)
            ->assertOk()
            ->assertJsonPath('data.1.display_name', 'Nome manual')
            ->assertJsonPath('data.1.display_name_source', 'MANUAL_CONTACT')
            ->assertJsonPath('data.1.display_name_state', 'CURATED');

        $this->getJson('/api/v1/communication/contacts?inbox_id='.$foreignInbox->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/communication/contacts/'.$first->id.'?inbox_id='.$foreignInbox->id)
            ->assertNotFound();
    }

    public function test_inbox_context_uses_the_canonical_provisional_fallback(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = $this->inbox($tenant, 'Fallback');
        $contact = $this->contact($tenant, null, provisional: true, active: true);
        $identity = $this->identity($tenant, $contact, '+5511999900299');
        $identity->forceFill(['address_masked' => ''])->save();
        $this->conversation($tenant, $inbox, $identity, now());

        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/contacts?inbox_id='.$inbox->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $contact->id)
            ->assertJsonPath('data.0.display_name', 'Provisório #'.$contact->id)
            ->assertJsonPath('data.0.display_name_source', 'OPAQUE_ID')
            ->assertJsonPath('data.0.display_name_state', 'FALLBACK');
    }

    public function test_contact_reads_and_mutations_follow_merge_redirect(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $survivor = $this->contact($tenant, 'Canônico', provisional: false, active: true);
        $this->identity($tenant, $survivor, '+5511999900010');
        $donor = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'merged_into_contact_id' => $survivor->id,
            'name' => null,
            'is_provisional' => true,
            'is_active' => false,
        ]);
        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/contacts/'.$donor->id)
            ->assertOk()
            ->assertJsonPath('data.id', $survivor->id);
        $this->patchJson('/api/v1/communication/contacts/'.$donor->id, [
            'name' => 'Atualizado pelo redirect',
        ])->assertOk()
            ->assertJsonPath('data.id', $survivor->id)
            ->assertJsonPath('data.name', 'Atualizado pelo redirect');
        $created = $this->postJson('/api/v1/communication/contacts/'.$donor->id.'/identities', [
            'phone' => '+5511999900011',
        ])->assertCreated();

        $this->assertDatabaseHas('communication_identities', [
            'id' => $created->json('data.id'),
            'tenant_id' => $tenant->id,
            'contact_id' => $survivor->id,
        ]);
        $listedContactIds = $this->getJson('/api/v1/communication/contacts?include_inactive=true')
            ->assertOk()
            ->json('data.*.id');
        $this->assertNotContains($donor->id, $listedContactIds);
        $export = $this->get('/api/v1/communication/contacts/'.$donor->id.'/export')
            ->assertOk();
        $payload = json_decode($export->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($survivor->id, data_get($payload, 'contact.id'));
    }

    public function test_mutations_require_manage_contacts_not_only_manage_inboxes(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inboxManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $contactManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();

        $this->assignProfile($viewer, $tenant, [
            TenantPermission::CommunicationView,
        ]);
        $this->assignProfile($inboxManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageInboxes,
        ]);
        $this->assignProfile($contactManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageContacts,
        ]);

        $contact = $this->contact($tenant, 'Alvo', provisional: false, active: true);
        $identity = $this->identity($tenant, $contact, '+5511999911111');
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        $mutations = [
            fn () => $this->postJson('/api/v1/communication/contacts', [
                'name' => 'Novo',
                'phone' => '+5511999922222',
            ]),
            fn () => $this->patchJson('/api/v1/communication/contacts/'.$contact->id, [
                'name' => 'Renomeado',
            ]),
            fn () => $this->postJson('/api/v1/communication/contacts/'.$contact->id.'/identities', [
                'phone' => '+5511999933333',
            ]),
            fn () => $this->postJson('/api/v1/communication/identities/'.$identity->id.'/links', [
                'client_id' => $client->id,
            ]),
            fn () => $this->get('/api/v1/communication/contacts/'.$contact->id.'/export'),
            fn () => $this->deleteJson('/api/v1/communication/contacts/'.$contact->id.'/personal-data'),
        ];

        foreach ([$viewer, $inboxManager] as $denied) {
            $this->authenticate($denied);
            foreach ($mutations as $mutation) {
                $mutation()->assertForbidden();
            }
        }

        $this->authenticate($contactManager);
        $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Permitido',
            'phone' => '+5511999944444',
        ])->assertCreated();
        $this->patchJson('/api/v1/communication/contacts/'.$contact->id, [
            'name' => 'Atualizado',
        ])->assertOk()->assertJsonPath('data.name', 'Atualizado');
        $this->postJson('/api/v1/communication/contacts/'.$contact->id.'/identities', [
            'phone' => '+5511999955555',
        ])->assertCreated();
        $link = $this->postJson('/api/v1/communication/identities/'.$identity->id.'/links', [
            'client_id' => $client->id,
        ])->assertCreated()
            ->assertJsonPath('data.client_name', $client->displayLabel());
        $this->deleteJson('/api/v1/communication/identities/'.$identity->id.'/links/'.$link->json('data.id'))
            ->assertNoContent();
        $this->get('/api/v1/communication/contacts/'.$contact->id.'/export')->assertOk();
    }

    public function test_manage_contacts_permission_is_in_admin_effective_set(): void
    {
        $this->assertSame('communication.manage_contacts', TenantPermission::CommunicationManageContacts->value);
        $this->assertSame('Gerenciar contatos de comunicação', TenantPermission::CommunicationManageContacts->label());
        $this->assertContains(
            TenantPermission::CommunicationManageContacts->value,
            TenantPermission::orderedValues(),
        );
    }

    public function test_contact_boundary_rejects_tenant_input_and_invalid_whatsapp_address(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $otherTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $contact = $this->contact($tenant, 'Contato', provisional: false, active: true);
        $this->authenticate($admin);

        $this->getJson('/api/v1/communication/contacts?tenant_id='.$otherTenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/communication/contacts', [
            'tenant_id' => $otherTenant->id,
            'name' => 'Escopo inválido',
            'phone' => '+5511999988888',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/communication/contacts', [
            'name' => 'Endereço inválido',
            'phone' => 'grupo@g.us',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->postJson('/api/v1/communication/contacts/'.$contact->id.'/identities', [
            'phone' => 'inválido',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('communication_contacts', [
            'tenant_id' => $tenant->id,
            'name' => 'Endereço inválido',
        ]);
    }

    public function test_identity_phone_is_safe_e164_and_never_exposes_technical_addresses(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $managerWithoutView = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $this->assignProfile($managerWithoutView, $tenant, [
            TenantPermission::CommunicationManageContacts,
        ]);
        $contact = $this->contact($tenant, 'Seguro', provisional: false, active: true);
        $phone = $this->identity($tenant, $contact, '+5511999900101');
        $lid = $this->identity($tenant, $contact, 'lid:123456789');
        $jid = $this->identity($tenant, $contact, '5511999900102@s.whatsapp.net');
        $invalid = $this->identity($tenant, $contact, 'not-a-phone');
        $email = $this->identity($tenant, $contact, '+5511999900105');
        $email->update(['channel' => CommunicationChannel::Email]);
        $purgedPhone = $this->identity($tenant, $contact, '+5511999900106');
        $purgedPhone->update(['purged_at' => now()]);
        $corrupted = $this->identity($tenant, $contact, '+5511999900107');
        DB::table('communication_identities')
            ->where('id', $corrupted->id)
            ->update(['address_encrypted' => 'ciphertext-inválido']);
        $purgedContact = $this->contact($tenant, 'Expurgado', provisional: false, active: false);
        $purgedContactPhone = $this->identity($tenant, $purgedContact, '+5511999900108');
        $purgedContact->update(['purged_at' => now()]);

        $this->authenticate($admin);
        $identities = collect($this->getJson('/api/v1/communication/contacts/'.$contact->id)
            ->assertOk()
            ->json('data.identities'))
            ->keyBy('id');

        $this->assertSame('+5511999900101', $identities[$phone->id]['phone']);
        $this->assertNull($identities[$lid->id]['phone']);
        $this->assertNull($identities[$jid->id]['phone']);
        $this->assertNull($identities[$invalid->id]['phone']);
        $this->assertNull($identities[$email->id]['phone']);
        $this->assertNull($identities[$purgedPhone->id]['phone']);
        $this->assertNull($identities[$corrupted->id]['phone']);
        foreach ($identities as $identity) {
            $this->assertArrayNotHasKey('address', $identity);
            $this->assertArrayNotHasKey('address_encrypted', $identity);
            $this->assertArrayNotHasKey('address_hash', $identity);
        }
        $this->getJson('/api/v1/communication/contacts/'.$purgedContact->id)
            ->assertOk()
            ->assertJsonPath('data.identities.0.id', $purgedContactPhone->id)
            ->assertJsonPath('data.identities.0.phone', null);

        $this->authenticate($managerWithoutView);
        $restricted = collect($this->patchJson('/api/v1/communication/contacts/'.$contact->id, [
            'name' => 'Seguro atualizado',
        ])
            ->assertOk()
            ->json('data.identities'))
            ->keyBy('id');
        $this->assertNull($restricted[$phone->id]['phone']);
        $this->getJson('/api/v1/communication/contacts/'.$contact->id)->assertForbidden();
    }

    public function test_contact_search_matches_normalized_phone_hash_without_cross_tenant_leak(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreignTenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $contact = $this->contact($tenant, 'Por telefone', provisional: false, active: true);
        $this->identity($tenant, $contact, '+5511999900103');
        $foreign = $this->contact($foreignTenant, 'Estrangeiro', provisional: false, active: true);
        $this->identity($foreignTenant, $foreign, '+5511999900104');

        $this->authenticate($admin);
        $this->postJson('/api/v1/communication/contacts/search', [
            'q' => '(11) 99990-0103',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $contact->id)
            ->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/communication/contacts/search', [
            'q' => '+5511999900104',
        ])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/communication/contacts/search', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/communication/contacts?q=%2B5511999900103')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $tenant->subscription()->update([
            'status' => SubscriptionStatus::Suspended,
        ]);
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/communication/contacts/search', [
            'q' => '(11) 99990-0103',
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $contact->id);
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

    private function contact(Tenant $tenant, ?string $name, bool $provisional, bool $active): CommunicationContact
    {
        return CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'is_provisional' => $provisional,
            'is_active' => $active,
        ]);
    }

    private function identity(Tenant $tenant, CommunicationContact $contact, string $address): CommunicationIdentity
    {
        return CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::WhatsApp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => substr($address, 0, min(3, strlen($address))).'•••••'.substr($address, -4),
            'is_active' => true,
        ]);
    }

    private function inbox(Tenant $tenant, string $name): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'session_id' => 'session-'.Str::lower((string) Str::ulid()),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
        ]);
    }

    private function conversation(
        Tenant $tenant,
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        mixed $lastMessageAt,
    ): CommunicationConversation {
        return CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => ConversationStatus::Open,
            'last_message_at' => $lastMessageAt,
        ]);
    }
}
