<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBulkStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_inactivate_clients_from_current_tenant_atomically(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        $clients = Client::factory()->count(2)->forTenant($tenant)->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => $clients->modelKeys(),
            'is_active' => false,
            'inactive_reason' => 'Inativado em massa pela lista de clientes',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.is_active', false);

        foreach ($clients as $client) {
            $this->assertDatabaseHas('clients', [
                'id' => $client->id,
                'tenant_id' => $tenant->id,
                'is_active' => false,
                'inactive_reason' => 'Inativado em massa pela lista de clientes',
            ]);
        }
    }

    public function test_reactivation_clears_inactive_reason(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create([
            'is_active' => false,
            'inactive_reason' => 'Motivo anterior',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$client->id],
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => true,
            'inactive_reason' => null,
        ]);
    }

    public function test_cross_tenant_id_rejects_the_entire_batch(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$ownClient->id, $otherClient->id],
            'is_active' => false,
            'inactive_reason' => 'Não deve ser aplicado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_ids');

        $this->assertDatabaseHas('clients', [
            'id' => $ownClient->id,
            'is_active' => true,
            'inactive_reason' => null,
        ]);
    }

    public function test_viewer_cannot_update_clients_in_bulk(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser, 'viewer');
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$client->id],
            'is_active' => false,
            'inactive_reason' => 'Sem permissão',
        ])->assertForbidden();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => true,
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role, string $permissionProfile = 'operator'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        if ($role === TenantRole::TenantUser) {
            $profiles = app(SystemTenantPermissionProfiles::class)->ensure($tenant);
            TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->update(['permission_profile_id' => $profiles[$permissionProfile]->id]);
        }

        return [$user, $tenant];
    }
}
