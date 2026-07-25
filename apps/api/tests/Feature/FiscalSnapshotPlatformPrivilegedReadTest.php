<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalSnapshotPlatformPrivilegedReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_privileged_admin_without_dual_membership_can_list_snapshots(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $actor = User::factory()->asPlatformAdmin($office->id)->create();

        Sanctum::actingAs($actor);
        $current = app(CurrentOffice::class);
        $current->clear();
        $current->bindPlatformPrivileged($actor, $office);

        $this->assertTrue($current->isPlatformPrivileged());
        $this->assertNull($current->realMembership());

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }

    public function test_tenant_user_without_permission_profile_is_forbidden(): void
    {
        config(['features.canonical_multitenant_rbac.enabled' => true]);

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $actor = User::factory()->create();
        OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $actor->id,
            'role' => OfficeRole::Viewer,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => null,
            'is_active' => true,
        ]);

        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertForbidden()
            ->assertJsonPath('message', 'Sem permissão para monitoramento fiscal.');
    }

    public function test_office_viewer_with_membership_can_list_snapshots(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();

        Sanctum::actingAs($viewer);
        app(CurrentOffice::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }
}
