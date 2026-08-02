<?php

namespace Tests\Feature;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\SavedListFilter;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SavedListFilterAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_owner_without_clients_view_cannot_address_own_filter(): void
    {
        [$tenant, $actor] = $this->actorWithPermissions([
            TenantPermission::TenantDashboardView,
        ]);
        $filter = $this->filter($tenant, $actor);
        $this->authenticate($actor);

        $this->assertFalse(Gate::forUser($actor)->allows('view', $filter));
        $this->patchJson('/api/v1/list-filters/'.$filter->id, ['name' => 'Bloqueado'])
            ->assertForbidden();
        $this->deleteJson('/api/v1/list-filters/'.$filter->id)
            ->assertForbidden();
        $this->assertDatabaseHas('saved_list_filters', [
            'id' => $filter->id,
            'name' => $filter->name,
        ]);
    }

    public function test_owner_with_clients_view_retains_normal_operations(): void
    {
        [$tenant, $actor] = $this->actorWithPermissions([TenantPermission::ClientsView]);
        $filter = $this->filter($tenant, $actor);
        $this->authenticate($actor);

        $this->assertTrue(Gate::forUser($actor)->allows('view', $filter));
        $this->patchJson('/api/v1/list-filters/'.$filter->id, ['name' => 'Atualizado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Atualizado');
        $this->deleteJson('/api/v1/list-filters/'.$filter->id)
            ->assertNoContent();
        $this->assertDatabaseMissing('saved_list_filters', ['id' => $filter->id]);
    }

    public function test_shared_filter_requires_clients_view_and_management_for_foreign_update(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        $filter = $this->filter($tenant, $owner, SavedListFilter::VISIBILITY_TENANT);
        [, $viewer] = $this->actorWithPermissions([TenantPermission::ClientsView], $tenant);
        [, $manager] = $this->actorWithPermissions([
            TenantPermission::ClientsView,
            TenantPermission::TenantSettingsManage,
        ], $tenant);

        $this->authenticate($viewer);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $filter));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $filter));

        $this->authenticate($manager);
        $this->assertTrue(Gate::forUser($manager)->allows('update', $filter));

        $foreignTenant = Tenant::factory()->create();
        $foreignFilter = $this->filter($foreignTenant, User::factory()->create());
        $this->assertFalse(Gate::forUser($manager)->allows('view', $foreignFilter));
    }

    public function test_communication_view_without_clients_view_can_crud_personal_conversation_view(): void
    {
        [$tenant, $actor] = $this->actorWithPermissions([
            TenantPermission::CommunicationView,
        ]);
        $this->authenticate($actor);

        $created = $this->postJson('/api/v1/list-filters', [
            'surface' => SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS,
            'name' => 'Minha fila',
            'visibility' => SavedListFilter::VISIBILITY_PERSONAL,
            'payload' => [
                'status' => 'OPEN',
                'sort_by' => 'last_activity_desc',
                'label_ids' => [3, 1],
                'unread' => true,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.surface', SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS)
            ->assertJsonPath('data.payload.label_ids', [1, 3]);

        $filterId = (int) $created->json('data.id');

        $this->getJson('/api/v1/list-filters?surface='.SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $filterId);

        $this->patchJson('/api/v1/list-filters/'.$filterId, [
            'name' => 'Minha fila atualizada',
            'payload' => [
                'status' => 'ALL',
                'sort_by' => 'created_desc',
                'unassigned' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Minha fila atualizada')
            ->assertJsonPath('data.payload.status', 'ALL');

        $this->assertDatabaseHas('saved_list_filters', [
            'id' => $filterId,
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'schema_version' => SavedListFilter::SCHEMA_VERSION,
        ]);

        $this->deleteJson('/api/v1/list-filters/'.$filterId)->assertNoContent();
        $this->assertDatabaseMissing('saved_list_filters', ['id' => $filterId]);
    }

    public function test_conversation_surface_requires_communication_view_instead_of_clients_view(): void
    {
        [, $actor] = $this->actorWithPermissions([TenantPermission::ClientsView]);
        $this->authenticate($actor);

        $this->getJson('/api/v1/list-filters?surface='.SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS)
            ->assertForbidden();

        $this->postJson('/api/v1/list-filters', $this->conversationBody())
            ->assertForbidden();

        $this->assertDatabaseMissing('saved_list_filters', [
            'surface' => SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS,
        ]);
    }

    public function test_conversation_payload_rejects_unknown_scoped_and_server_owned_fields(): void
    {
        [, $actor] = $this->actorWithPermissions([TenantPermission::CommunicationView]);
        $this->authenticate($actor);

        $invalidCases = [
            ['override' => ['payload' => [...$this->conversationPayload(), 'q' => 'cliente']], 'field' => 'payload.q'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'contact_id' => 10]], 'field' => 'payload.contact_id'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'page' => 2]], 'field' => 'payload.page'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'selected_ids' => [1]]], 'field' => 'payload.selected_ids'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'conversation_id' => 1]], 'field' => 'payload.conversation_id'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'tenant_id' => 99]], 'field' => 'tenant_id'],
            ['override' => ['payload' => [...$this->conversationPayload(), 'schema_version' => 1]], 'field' => 'payload.schema_version'],
            ['override' => ['tenant_id' => 99], 'field' => 'tenant_id'],
            ['override' => ['schema_version' => 1], 'field' => 'schema_version'],
        ];

        foreach ($invalidCases as $index => $case) {
            $body = array_replace($this->conversationBody(), $case['override']);
            $body['name'] = 'Inválido '.$index;

            $this->postJson('/api/v1/list-filters', $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($case['field']);
        }

        $this->assertDatabaseMissing('saved_list_filters', [
            'surface' => SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS,
        ]);
    }

    public function test_conversation_payload_rejects_invalid_types_and_enums(): void
    {
        [, $actor] = $this->actorWithPermissions([TenantPermission::CommunicationView]);
        $this->authenticate($actor);

        $this->postJson('/api/v1/list-filters', [
            ...$this->conversationBody(),
            'payload' => [
                'status' => 'INVALID',
                'sort_by' => 'unsupported',
                'inbox_id' => 0,
                'label_ids' => [1, 1],
                'unread' => 'sometimes',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payload.status',
                'payload.sort_by',
                'payload.inbox_id',
                'payload.label_ids.1',
                'payload.unread',
            ]);
    }

    public function test_conversation_tenant_share_still_requires_filters_share(): void
    {
        [$tenant, $actor] = $this->actorWithPermissions([TenantPermission::CommunicationView]);
        $this->authenticate($actor);

        $this->postJson('/api/v1/list-filters', [
            ...$this->conversationBody(),
            'visibility' => SavedListFilter::VISIBILITY_TENANT,
        ])->assertForbidden();

        [, $shareOnly] = $this->actorWithPermissions([
            TenantPermission::FiltersShare,
        ], $tenant);
        $this->authenticate($shareOnly);

        $this->postJson('/api/v1/list-filters', [
            ...$this->conversationBody(),
            'visibility' => SavedListFilter::VISIBILITY_TENANT,
        ])->assertForbidden();

        [, $sharer] = $this->actorWithPermissions([
            TenantPermission::CommunicationView,
            TenantPermission::FiltersShare,
        ], $tenant);
        $this->authenticate($sharer);

        $this->postJson('/api/v1/list-filters', [
            ...$this->conversationBody(),
            'visibility' => SavedListFilter::VISIBILITY_TENANT,
        ])->assertCreated()
            ->assertJsonPath('data.visibility', SavedListFilter::VISIBILITY_TENANT);
    }

    public function test_existing_surface_keeps_clients_view_authorization(): void
    {
        [, $actor] = $this->actorWithPermissions([TenantPermission::ClientsView]);
        $this->authenticate($actor);

        $this->postJson('/api/v1/list-filters', [
            'surface' => 'clients.index',
            'name' => 'Clientes ativos',
            'visibility' => SavedListFilter::VISIBILITY_PERSONAL,
            'payload' => [
                'q' => '',
                'status' => 'active',
                'operational_filter' => 'total',
                'category_ids' => '',
                'tax_regimes' => '',
                'procuracao_statuses' => '',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.surface', 'clients.index');
    }

    public function test_foreign_tenant_conversation_filter_binding_is_not_revealed(): void
    {
        [, $actor] = $this->actorWithPermissions([TenantPermission::CommunicationView]);
        $foreignTenant = Tenant::factory()->create();
        $foreignOwner = User::factory()->create();
        $foreignFilter = $this->filter(
            $foreignTenant,
            $foreignOwner,
            surface: SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS,
            payload: $this->conversationPayload(),
        );
        $this->authenticate($actor);

        $this->patchJson('/api/v1/list-filters/'.$foreignFilter->id, [
            'name' => 'Não revelar',
        ])->assertNotFound();
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return array{Tenant, User}
     */
    private function actorWithPermissions(array $permissions, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $actor = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys($permissions);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);

        return [$tenant, $actor];
    }

    private function filter(
        Tenant $tenant,
        User $owner,
        string $visibility = SavedListFilter::VISIBILITY_PERSONAL,
        string $surface = 'clients.index',
        ?array $payload = null,
    ): SavedListFilter {
        return SavedListFilter::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'surface' => $surface,
            'name' => 'Filtro '.strtolower((string) str()->ulid()),
            'visibility' => $visibility,
            'schema_version' => 1,
            'payload' => $payload ?? [
                'q' => '',
                'status' => 'all',
                'operational_filter' => 'total',
                'category_ids' => '',
                'tax_regimes' => '',
                'procuracao_statuses' => '',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function conversationBody(): array
    {
        return [
            'surface' => SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS,
            'name' => 'Conversas prioritárias',
            'visibility' => SavedListFilter::VISIBILITY_PERSONAL,
            'payload' => $this->conversationPayload(),
        ];
    }

    /** @return array<string, mixed> */
    private function conversationPayload(): array
    {
        return [
            'status' => 'OPEN',
            'sort_by' => 'last_activity_desc',
        ];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
