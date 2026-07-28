<?php

namespace Tests\Feature;

use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformFiscalModuleControlApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_lists_ten_available_modules_by_default(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/fiscal/modules')
            ->assertOk()
            ->assertJsonPath('data.profile', 'dev')
            ->assertJsonCount(10, 'data.modules')
            ->assertJsonPath('data.modules.0.allowed', true);
    }

    public function test_non_platform_admin_cannot_read_or_change_controls(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/fiscal/modules')->assertForbidden();
        $this->patchJson('/api/v1/platform/fiscal/modules/mailbox/restriction', [
            'restricted' => true,
            'reason' => 'Pausa',
        ])->assertForbidden();
    }

    public function test_global_and_tenant_endpoints_restrict_immediately_and_release_with_recent_password(): void
    {
        $actor = $this->platformAdmin();
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($actor);

        $this->patchJson('/api/v1/platform/fiscal/modules/mailbox/restriction', [
            'restricted' => true,
            'reason' => 'Pausa operacional',
        ])->assertOk()->assertJsonPath('data.state', 'GLOBALLY_RESTRICTED');

        $this->patchJson("/api/v1/platform/tenants/{$tenant->id}/fiscal/modules/mailbox/restriction", [
            'restricted' => true,
            'reason' => 'Pausa específica do tenant',
        ])->assertOk()
            ->assertJsonPath('data.tenant_restriction.restricted', true);

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}/fiscal/modules")
            ->assertOk()
            ->assertJsonPath('data.modules.4.state', 'GLOBALLY_RESTRICTED');

        $this->patchJson('/api/v1/platform/fiscal/modules/mailbox/restriction', [
            'restricted' => false,
            'reason' => 'Operação normalizada',
        ])->assertForbidden()->assertJsonPath('code', 'password_confirmation_required');

        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);
        $this->patchJson('/api/v1/platform/fiscal/modules/mailbox/restriction', [
            'restricted' => false,
            'reason' => 'Operação normalizada',
        ])->assertOk()->assertJsonPath('data.state', 'AVAILABLE');
    }

    public function test_control_update_rejects_unknown_fields(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);

        $this->patchJson('/api/v1/platform/fiscal/modules/mailbox/restriction', [
            'restricted' => true,
            'reason' => 'Pausa operacional',
            'enabled' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('enabled');
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        PlatformMembership::factory()->forUser($user)->create();

        return $user;
    }
}
