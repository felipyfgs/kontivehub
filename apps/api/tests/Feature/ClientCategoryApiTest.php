<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_normalized_catalog_and_viewer_can_read_it(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/client-categories', [
            'name' => '  Cliente   VIP  ',
            'color' => 'rose',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Cliente VIP')
            ->assertJsonPath('data.color', 'rose')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.clients_count', 0);

        $categoryId = (int) $created->json('data.id');
        $this->postJson('/api/v1/client-categories', [
            'name' => 'cliente vip',
            'color' => 'info',
        ])->assertUnprocessable()->assertJsonValidationErrors('_name_key');

        $this->patchJson("/api/v1/client-categories/{$categoryId}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/client-categories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/client-categories?include_archived=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $categoryId);

        $this->patchJson("/api/v1/client-categories/{$categoryId}", [
            'is_active' => true,
            'color' => 'primary',
        ])->assertOk()->assertJsonPath('data.is_active', true);

        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/client-categories?include_archived=1')->assertOk();
        $this->postJson('/api/v1/client-categories', [
            'name' => 'Bloqueada',
            'color' => 'neutral',
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'office_id' => $office->id,
            'action' => 'client_category.reactivate',
        ]);
    }

    public function test_operator_replaces_categories_but_cannot_manage_catalog(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $operator = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->forOffice($office)->create();
        $active = $this->category($office, 'Atendimento', true);
        $archived = $this->category($office, 'Legado', false);

        Sanctum::actingAs($operator);
        $this->postJson('/api/v1/client-categories', [
            'name' => 'Sem acesso',
            'color' => 'neutral',
        ])->assertForbidden();

        $this->putJson("/api/v1/clients/{$client->id}/categories", [
            'category_ids' => [$active->id],
        ])->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.categories.0.id', $active->id);

        $this->putJson("/api/v1/clients/{$client->id}/categories", [
            'category_ids' => [$active->id, $archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('category_ids');

        DB::table('client_category_assignments')->insert([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'client_category_id' => $archived->id,
            'assigned_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/clients/{$client->id}/categories", [
            'category_ids' => [$active->id],
        ])->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertDatabaseMissing('client_category_assignments', [
            'client_id' => $client->id,
            'client_category_id' => $archived->id,
        ]);
    }

    public function test_bulk_assignment_is_atomic_across_tenants_and_can_remove_archived_categories(): void
    {
        [$operator, $office] = $this->actor(OfficeRole::Operator);
        $otherOffice = Office::factory()->create();
        $clients = Client::factory()->count(2)->forOffice($office)->create();
        $ownCategory = $this->category($office, 'Cobrança', true);
        $otherCategory = $this->category($otherOffice, 'Outro tenant', true);
        Sanctum::actingAs($operator);

        $this->patchJson('/api/v1/clients/bulk-categories', [
            'operation' => 'add',
            'client_ids' => $clients->modelKeys(),
            'category_ids' => [$ownCategory->id, $otherCategory->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('category_ids');

        $this->assertDatabaseCount('client_category_assignments', 0);

        $this->patchJson('/api/v1/clients/bulk-categories', [
            'operation' => 'add',
            'client_ids' => $clients->modelKeys(),
            'category_ids' => [$ownCategory->id],
        ])->assertOk()
            ->assertJsonPath('data.updated_clients', 2)
            ->assertJsonPath('data.created_links', 2);

        $ownCategory->forceFill(['is_active' => false])->save();
        $this->patchJson('/api/v1/clients/bulk-categories', [
            'operation' => 'remove',
            'client_ids' => $clients->modelKeys(),
            'category_ids' => [$ownCategory->id],
        ])->assertOk()->assertJsonPath('data.removed_links', 2);
    }

    public function test_client_list_filters_categories_with_or_semantics_and_current_tax_regime(): void
    {
        [$viewer, $office] = $this->actor(OfficeRole::Viewer);
        $priority = $this->category($office, 'Prioridade', true);
        $onboarding = $this->category($office, 'Onboarding', true);
        $mei = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Alfa MEI',
            'tax_regime' => 'MEI',
        ]);
        $simples = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Beta Simples',
            'tax_regime' => 'SIMPLES_NACIONAL',
        ]);
        $real = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Gama Real',
            'tax_regime' => 'LUCRO_REAL',
        ]);
        $this->assign($office, $mei, $priority);
        $this->assign($office, $simples, $onboarding);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/clients?category_ids='.$priority->id.','.$onboarding->id.'&per_page=20')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/clients?category_ids='.$priority->id.'&tax_regimes=MEI,SIMPLES_NACIONAL&q=Alfa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mei->id)
            ->assertJsonPath('data.0.tax_regime', 'MEI')
            ->assertJsonPath('data.0.tax_regime_label', 'MEI / SIMEI')
            ->assertJsonPath('data.0.categories.0.id', $priority->id);

        $sorted = $this->getJson('/api/v1/clients?sort=tax_regime&direction=asc&per_page=20')
            ->assertOk();
        $this->assertSame($real->id, $sorted->json('data.0.id'));
    }

    public function test_legacy_tax_regime_labels_are_normalized_without_writing_period_history(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'tax_regime' => 'Imune/Isento',
        ])->assertOk()
            ->assertJsonPath('data.tax_regime', 'IMUNE_ISENTO')
            ->assertJsonPath('data.tax_regime_label', 'Imune / Isento');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'tax_regime' => 'IMUNE_ISENTO',
        ]);
        $this->assertDatabaseCount('client_tax_regime_periods', 0);
    }

    private function category(Office $office, string $name, bool $isActive): ClientCategory
    {
        return ClientCategory::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => $name,
            'name_key' => ClientCategory::normalizeNameKey($name),
            'color' => 'primary',
            'is_active' => $isActive,
        ]);
    }

    private function assign(Office $office, Client $client, ClientCategory $category): void
    {
        DB::table('client_category_assignments')->insert([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'client_category_id' => $category->id,
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }
}
