<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class AuthenticationAndTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_confirmation_preserves_failure_and_success_contracts(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/confirm-password', [
            'password' => 'incorrect-password',
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Senha inválida.',
                'code' => 'PASSWORD_INVALID',
            ]);

        $response = $this->postJson('/api/v1/auth/confirm-password', [
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.window_minutes', 15)
            ->assertJsonStructure([
                'data' => [
                    'confirmed',
                    'window_minutes',
                    'seconds_remaining',
                ],
            ]);

        $this->assertGreaterThan(0, $response->json('data.seconds_remaining'));
        $this->assertLessThanOrEqual(900, $response->json('data.seconds_remaining'));
    }

    public function test_password_confirmation_rejects_unknown_fields(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/confirm-password', [
            'password' => 'correct-password',
            'remember_device' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('remember_device');
    }

    public function test_password_confirmation_does_not_mask_hashing_failures_as_invalid_password(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'password' => 'not-a-supported-password-hash',
        ]);
        $user->refresh();
        Sanctum::actingAs($user);

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);

        $this->postJson('/api/v1/auth/confirm-password', [
            'password' => 'any-password',
        ]);
    }

    public function test_account_update_normalizes_identity_and_preserves_response_contract(): void
    {
        $user = User::factory()->create([
            'name' => 'Nome antigo',
            'email' => 'old@example.test',
        ]);
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $this->patchJson('/api/v1/account', [
            'name' => '  Nome Atualizado  ',
            'email' => '  UPDATED@EXAMPLE.TEST ',
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => 'Nome Atualizado',
                    'email' => 'updated@example.test',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nome Atualizado',
            'email' => 'updated@example.test',
        ]);
    }

    public function test_account_update_rejects_unknown_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $this->patchJson('/api/v1/account', [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'America/Sao_Paulo',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_account_update_rejects_structured_identity_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $this->patchJson('/api/v1/account', [
            'name' => ['Nome inválido'],
            'email' => ['email@example.test'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_tenant_memberships_and_switch_preserve_their_contracts(): void
    {
        $primary = Tenant::factory()->create(['name' => 'Escritório Principal']);
        $secondary = Tenant::factory()->create([
            'name' => 'Escritório Secundário',
            'slug' => 'escritorio-secundario',
        ]);
        $user = User::factory()->forTenant($primary, TenantRole::TenantUser)->create();
        $secondary->users()->attach($user->id, [
            'role' => TenantRole::TenantAdmin->value,
            'permission_profile_id' => null,
            'is_active' => true,
        ]);
        $user->forceFill(['selected_tenant_id' => $primary->id])->saveQuietly();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tenants/memberships')
            ->assertOk()
            ->assertJsonPath('data.current_tenant_id', $primary->id)
            ->assertJsonCount(2, 'data.memberships');

        $this->postJson('/api/v1/tenants/switch', [
            'tenant_id' => $secondary->id,
        ])->assertOk()
            ->assertExactJson([
                'data' => [
                    'tenant' => [
                        'id' => $secondary->id,
                        'name' => 'Escritório Secundário',
                        'slug' => 'escritorio-secundario',
                    ],
                    'role' => TenantRole::TenantAdmin->value,
                ],
            ]);

        $this->assertSame($secondary->id, $user->refresh()->selected_tenant_id);
    }

    public function test_tenant_switch_rejects_unknown_fields_and_unrelated_tenants(): void
    {
        $ownTenant = Tenant::factory()->create();
        $unrelatedTenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($ownTenant, TenantRole::TenantUser)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tenants/switch', [
            'tenant_id' => $ownTenant->id,
            'tenant_slug' => $ownTenant->slug,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_slug');

        $this->postJson('/api/v1/tenants/switch', [
            'tenant_id' => $unrelatedTenant->id,
        ])->assertNotFound();
    }
}
