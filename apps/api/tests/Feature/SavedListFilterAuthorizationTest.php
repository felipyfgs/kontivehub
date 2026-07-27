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
    ): SavedListFilter {
        return SavedListFilter::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'surface' => 'clients.index',
            'name' => 'Filtro '.strtolower((string) str()->ulid()),
            'visibility' => $visibility,
            'schema_version' => 1,
            'payload' => [
                'q' => '',
                'status' => 'all',
                'operational_filter' => 'total',
                'category_ids' => '',
                'tax_regimes' => '',
                'procuracao_statuses' => '',
            ],
        ]);
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
