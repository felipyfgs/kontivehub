<?php

namespace Tests\Feature;

use App\Enums\ActivationMethod;
use App\Enums\TenantRole;
use App\Http\Requests\Tenant\ReactivateMemberRequest;
use App\Http\Requests\Tenant\RegenerateMemberActivationRequest;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantMemberApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_method_accessors_do_not_shadow_the_http_method(): void
    {
        $reactivate = ReactivateMemberRequest::create(
            '/api/v1/tenant/members/1/reactivate',
            'POST',
        );
        $regenerate = RegenerateMemberActivationRequest::create(
            '/api/v1/tenant/members/1/activation/regenerate',
            'POST',
        );

        self::assertSame('POST', $reactivate->method());
        self::assertSame('POST', $regenerate->method());
    }

    public function test_admin_lists_only_current_tenant_and_client_scope_is_rejected(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $otherAdmin = User::factory()
            ->forTenant($otherTenant, TenantRole::TenantAdmin)
            ->create();
        $this->authenticate($admin);

        $this->getJson('/api/v1/tenant/members')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $admin->id)
            ->assertJsonPath('data.0.role', TenantRole::TenantAdmin->value)
            ->assertJsonPath('meta.occupied_seats', 1)
            ->assertJsonPath('meta.max_users', $tenant->subscription->max_users)
            ->assertJsonMissing([$otherAdmin->email]);

        $this->getJson("/api/v1/tenant/members?tenant_id={$otherTenant->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/members')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_creation_requires_recent_password_and_whitelists_one_time_delivery(): void
    {
        [$admin, $tenant] = $this->admin();
        $this->authenticate($admin);
        $payload = [
            'name' => 'Novo Operador',
            'email' => 'novo-operador@example.test',
            'role' => TenantRole::TenantUser->value,
            'method' => ActivationMethod::ManualLink->value,
        ];

        $this->postJson('/api/v1/tenant/members', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');

        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);

        $this->postJson('/api/v1/tenant/members', [
            ...$payload,
            'email' => 'inválido',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $response = $this->postJson('/api/v1/tenant/members', $payload)
            ->assertCreated()
            ->assertJsonPath('data.credential_delivery', 'delivered')
            ->assertJsonPath('data.method', ActivationMethod::ManualLink->value)
            ->assertJsonPath('data.membership.email', $payload['email'])
            ->assertJsonPath(
                'data.membership.role',
                TenantRole::TenantUser->value,
            )
            ->assertJsonStructure([
                'data' => [
                    'activation_url',
                    'expires_at',
                    'membership' => ['id', 'user_id', 'status', 'activation'],
                ],
            ])
            ->assertJsonMissingPath('data.secret_hash')
            ->assertJsonMissingPath('data.membership.activation.secret_hash');
        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'id' => $response->json('data.membership.id'),
            'is_active' => false,
        ]);
    }

    public function test_member_mutations_reject_cross_tenant_and_protect_last_admin(): void
    {
        [$admin] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $otherAdmin = User::factory()
            ->forTenant($otherTenant, TenantRole::TenantAdmin)
            ->create();
        $otherMembership = TenantMembership::query()
            ->where('tenant_id', $otherTenant->id)
            ->where('user_id', $otherAdmin->id)
            ->firstOrFail();
        $ownMembership = TenantMembership::query()
            ->where('user_id', $admin->id)
            ->firstOrFail();
        $this->authenticate($admin);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);

        $this->patchJson(
            "/api/v1/tenant/members/{$otherMembership->id}",
            ['role' => TenantRole::TenantUser->value],
        )->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        $this->postJson(
            "/api/v1/tenant/members/{$ownMembership->id}/deactivate",
        )->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->patchJson(
            "/api/v1/tenant/members/{$ownMembership->id}",
            ['role' => TenantRole::TenantUser->value],
        )->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $ownMembership->id,
            'role' => TenantRole::TenantAdmin->value,
            'is_active' => true,
        ]);
    }

    public function test_seat_limit_failure_is_stable_and_rolls_back_creation(): void
    {
        [$admin, $tenant] = $this->admin();
        $tenant->subscription->forceFill(['max_users' => 1])->save();
        $this->authenticate($admin);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);

        $this->postJson('/api/v1/tenant/members', [
            'name' => 'Sem Vaga',
            'email' => 'sem-vaga@example.test',
            'role' => TenantRole::TenantUser->value,
            'method' => ActivationMethod::TemporaryPassword->value,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'seat_limit_reached')
            ->assertJsonMissingPath('temporary_password');

        $this->assertDatabaseMissing('users', [
            'email' => 'sem-vaga@example.test',
        ]);
        $this->assertDatabaseCount('account_activations', 0);
    }

    /** @return array{User, Tenant} */
    private function admin(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();

        return [$admin, $tenant];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
