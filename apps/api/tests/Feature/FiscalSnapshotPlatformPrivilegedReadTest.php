<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalSnapshotPlatformPrivilegedReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_privileged_admin_without_dual_membership_can_list_snapshots(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();

        Sanctum::actingAs($actor);
        $current = app(CurrentTenant::class);
        $current->clear();
        $current->bindPlatformPrivileged($actor, $tenant);

        $this->assertTrue($current->isPlatformPrivileged());
        $this->assertNull($current->realMembership());

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }

    public function test_tenant_user_with_empty_permission_profile_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $actor = User::factory()->create();
        $profile = TenantPermissionProfile::query()->create([
            'tenant_id' => $tenant->id,
            'key' => 'empty',
            'name' => 'Sem permissões',
            'is_system' => false,
            'is_active' => true,
        ]);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertForbidden()
            ->assertJsonPath('message', 'Sem permissão para monitoramento fiscal.');
    }

    public function test_tenant_viewer_with_membership_can_list_snapshots(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();

        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }
}
