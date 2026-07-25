<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\SavedListFilter;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
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
        config(['features.canonical_multitenant_rbac.enabled' => true]);
    }

    public function test_owner_without_clients_view_cannot_address_own_filter(): void
    {
        [$office, $actor] = $this->actorWithPermissions([
            TenantPermission::TenantDashboardView,
        ]);
        $filter = $this->filter($office, $actor);
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
        [$office, $actor] = $this->actorWithPermissions([TenantPermission::ClientsView]);
        $filter = $this->filter($office, $actor);
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
        $office = Office::factory()->create();
        $owner = User::factory()->create();
        $filter = $this->filter($office, $owner, SavedListFilter::VISIBILITY_OFFICE);
        [, $viewer] = $this->actorWithPermissions([TenantPermission::ClientsView], $office);
        [, $manager] = $this->actorWithPermissions([
            TenantPermission::ClientsView,
            TenantPermission::TenantSettingsManage,
        ], $office);

        $this->authenticate($viewer);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $filter));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $filter));

        $this->authenticate($manager);
        $this->assertTrue(Gate::forUser($manager)->allows('update', $filter));

        $foreignOffice = Office::factory()->create();
        $foreignFilter = $this->filter($foreignOffice, User::factory()->create());
        $this->assertFalse(Gate::forUser($manager)->allows('view', $foreignFilter));
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return array{Office, User}
     */
    private function actorWithPermissions(array $permissions, ?Office $office = null): array
    {
        $office ??= Office::factory()->create();
        $actor = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys($permissions);
        OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $actor->id,
            'role' => OfficeRole::Viewer,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);

        return [$office, $actor];
    }

    private function filter(
        Office $office,
        User $owner,
        string $visibility = SavedListFilter::VISIBILITY_PERSONAL,
    ): SavedListFilter {
        return SavedListFilter::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'user_id' => $owner->id,
            'surface' => 'clients',
            'name' => 'Filtro '.strtolower((string) str()->ulid()),
            'visibility' => $visibility,
            'schema_version' => 1,
            'payload' => [],
        ]);
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
