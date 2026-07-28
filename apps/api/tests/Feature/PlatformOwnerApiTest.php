<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlatformOwnerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_show_and_update_preserve_their_contracts(): void
    {
        $initialTenant = Tenant::factory()->create(['name' => 'Tenant inicial']);
        $newTenant = Tenant::factory()->create([
            'name' => 'Tenant padrão',
            'slug' => 'tenant-padrao',
        ]);
        $owner = User::factory()->asPlatformAdmin($initialTenant->id)->create([
            'name' => 'Proprietário',
            'email' => 'owner@example.test',
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/platform/owner')
            ->assertOk()
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.email', 'owner@example.test')
            ->assertJsonPath('data.default_tenant_id', $initialTenant->id);

        app(RecentPasswordConfirmationGate::class)->markConfirmed($owner);

        $this->patchJson('/api/v1/platform/owner', [
            'name' => '  Proprietário Atualizado  ',
            'email' => 'OWNER.UPDATED@EXAMPLE.TEST',
            'default_tenant_id' => $newTenant->id,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.user_id', $owner->id)
            ->assertJsonPath('data.name', 'Proprietário Atualizado')
            ->assertJsonPath('data.email', 'owner.updated@example.test')
            ->assertJsonPath('data.default_tenant_id', $newTenant->id)
            ->assertJsonPath('data.default_tenant.id', $newTenant->id)
            ->assertJsonPath('data.default_tenant.slug', 'tenant-padrao');
    }

    public function test_platform_owner_update_rejects_empty_unknown_and_unavailable_email(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->asPlatformAdmin($tenant->id)->create();
        $other = User::factory()->create(['email' => 'used@example.test']);
        Sanctum::actingAs($owner);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($owner);

        $this->patchJson('/api/v1/platform/owner', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Nenhum campo para atualizar.',
                'code' => 'platform_owner_invalid',
            ]);

        $this->patchJson('/api/v1/platform/owner', [
            'name' => $owner->name,
            'timezone' => 'America/Sao_Paulo',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');

        $this->patchJson('/api/v1/platform/owner', [
            'email' => $other->email,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Não foi possível concluir com o e-mail informado.',
                'code' => 'email_unavailable',
            ]);
    }
}
