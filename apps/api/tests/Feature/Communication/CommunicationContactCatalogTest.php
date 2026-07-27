<?php

namespace Tests\Feature\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => substr($address, 0, min(3, strlen($address))).'•••••'.substr($address, -4),
            'is_active' => true,
        ]);
    }
}
