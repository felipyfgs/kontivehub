<?php

namespace Tests\Feature;

use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSessionNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_identity_and_selector_do_not_require_tenant_membership(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);
        Cache::flush();

        $defaultTenant = Tenant::factory()->create(['name' => 'Plataforma']);
        $selectableTenant = Tenant::factory()->create(['name' => 'Contador']);
        $actor = User::factory()->asPlatformAdmin($defaultTenant->id)->create();

        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.platform_role', 'platform_admin')
            ->assertJsonPath('data.tenant_role', 'tenant_admin')
            ->assertJsonPath('data.access_mode', 'platform_privileged')
            ->assertJsonPath('data.context_status', 'ok')
            ->assertJsonPath('data.current_tenant.id', $defaultTenant->id)
            ->assertJsonPath('data.has_real_membership', false)
            ->assertJsonCount(0, 'data.memberships')
            ->assertJsonFragment(['clients.manage']);

        $this->getJson('/api/v1/platform/tenants/selector')
            ->assertOk()
            ->assertJsonPath('data.selected_tenant_id', $defaultTenant->id)
            ->assertJsonPath('data.default_tenant_id', $defaultTenant->id)
            ->assertJsonCount(2, 'data.tenants')
            ->assertJsonFragment([
                'id' => $selectableTenant->id,
                'name' => 'Contador',
                'selectable' => true,
            ]);

        $this->postJson('/api/v1/platform/tenants/select', [
            'tenant_id' => $selectableTenant->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $selectableTenant->id)
            ->assertJsonPath('data.access_mode', 'platform_privileged')
            ->assertJsonPath('data.has_real_membership', false);

        $this->assertSame(0, TenantMembership::query()->where('user_id', $actor->id)->count());
        $this->assertSame(
            $selectableTenant->id,
            PlatformMembership::query()->where('user_id', $actor->id)->value('default_tenant_id'),
        );
    }
}
