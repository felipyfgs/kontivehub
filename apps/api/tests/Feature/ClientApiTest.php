<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientCustomField;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_sanitized_detail_only_from_current_tenant(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Cliente correto',
        ]);
        Establishment::factory()->forClient($client)->create([
            'trade_name' => 'Matriz correta',
        ]);
        $secret = $this->customField($client, 'SECRET', [
            'vault_object_id' => (string) Str::ulid(),
        ]);
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $this->authenticate($viewer);

        $this->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.legal_name', 'Cliente correto')
            ->assertJsonPath('data.establishments.0.trade_name', 'Matriz correta')
            ->assertJsonPath('data.custom_fields.0.id', $secret->id)
            ->assertJsonPath('data.custom_fields.0.value', null)
            ->assertJsonPath('data.custom_fields.0.has_value', true)
            ->assertJsonMissingPath('data.custom_fields.0.vault_object_id');

        $this->getJson("/api/v1/clients/{$otherClient->id}")
            ->assertNotFound();
    }

    public function test_operator_updates_declared_fields_and_rejects_scope_or_immutable_data(): void
    {
        [$operator, $tenant] = $this->actor('operator');
        $client = Client::factory()->forTenant($tenant)->create();
        $otherTenant = Tenant::factory()->create();
        $this->authenticate($operator);

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'display_name' => 'Nome operacional',
            'notes' => 'Observação interna',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Nome operacional');

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'tenant_id' => $otherTenant->id,
            'display_name' => 'Escopo injetado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'root_cnpj' => '99888777',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('root_cnpj');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'tenant_id' => $tenant->id,
            'display_name' => 'Nome operacional',
            'root_cnpj' => $client->root_cnpj,
        ]);

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);
        $this->patchJson("/api/v1/clients/{$client->id}", [
            'display_name' => 'Bloqueado',
        ])->assertForbidden();
    }

    public function test_custom_field_update_enforces_parent_and_never_writes_secret_as_text(): void
    {
        [$operator, $tenant] = $this->actor('operator');
        $first = Client::factory()->forTenant($tenant)->create();
        $second = Client::factory()->forTenant($tenant)->create();
        $text = $this->customField($first, 'TEXT');
        $secret = $this->customField($first, 'SECRET', [
            'vault_object_id' => (string) Str::ulid(),
        ]);
        $this->authenticate($operator);

        $this->patchJson(
            "/api/v1/clients/{$first->id}/custom-fields/{$text->id}",
            ['label' => 'Referência', 'value' => 'Valor permitido'],
        )->assertOk()
            ->assertJsonPath('data.label', 'Referência')
            ->assertJsonPath('data.value', 'Valor permitido');

        $this->patchJson(
            "/api/v1/clients/{$first->id}/custom-fields/{$secret->id}",
            ['value' => 'segredo-em-texto'],
        )->assertOk()
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.has_value', true);

        $this->patchJson(
            "/api/v1/clients/{$second->id}/custom-fields/{$text->id}",
            ['value' => 'parent incorreto'],
        )->assertNotFound();

        $this->assertDatabaseHas('client_custom_fields', [
            'id' => $secret->id,
            'value_text' => null,
        ]);
    }

    private function customField(
        Client $client,
        string $type,
        array $attributes = [],
    ): ClientCustomField {
        return ClientCustomField::query()->withoutGlobalScopes()->create([
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'field_key' => (string) Str::ulid(),
            'label' => "Campo {$type}",
            'type' => $type,
            'is_active' => true,
            'value_text' => null,
            'vault_object_id' => null,
            ...$attributes,
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(string $profile): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, $profile)
            ->create();

        return [$actor, $tenant];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
