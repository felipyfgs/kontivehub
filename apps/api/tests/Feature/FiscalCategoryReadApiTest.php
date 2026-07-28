<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;
use App\Enums\FiscalMutability;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\Tenant;
use App\Models\TenantFiscalCategoryLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalCategoryReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_lists_active_categories_and_current_tenant_links(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $category = $this->category();
        $this->category([
            'code' => 'INACTIVE_CATEGORY',
            'is_active' => false,
            'sort_order' => 2,
        ]);
        $own = $this->link($tenant, $client, $category);
        $foreign = $this->link($otherTenant, $otherClient, $category);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/categories')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $category->id,
                    'code' => 'TEST_CATEGORY',
                    'name' => 'Categoria de teste',
                    'module_key' => 'TEST',
                    'default_coverage' => 'FULL',
                    'default_mutability' => 'READ_ONLY',
                    'system_code' => 'TEST',
                    'service_code' => 'READ',
                    'is_active' => true,
                    'sort_order' => 1,
                    'description' => null,
                ]],
            ]);

        $this->getJson('/api/v1/fiscal/category-links?client_id='.$client->id.'&status=ACTIVE')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => $own->id,
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'fiscal_category_id' => $category->id,
                    'category_code' => 'TEST_CATEGORY',
                    'category_name' => 'Categoria de teste',
                    'status' => 'ACTIVE',
                    'coverage' => 'FULL',
                    'activated_at' => $own->activated_at?->toIso8601String(),
                    'deactivated_at' => null,
                    'notes' => null,
                ]],
            ])
            ->assertJsonMissing(['id' => $foreign->id]);
    }

    public function test_category_link_filters_are_validated_and_tenant_scope_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/category-links?status=INVALID')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/fiscal/category-links?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function category(array $overrides = []): FiscalCategory
    {
        return FiscalCategory::query()->create(array_merge([
            'code' => 'TEST_CATEGORY',
            'name' => 'Categoria de teste',
            'module_key' => 'TEST',
            'default_coverage' => FiscalCoverage::Full,
            'default_mutability' => FiscalMutability::ReadOnly,
            'system_code' => 'TEST',
            'service_code' => 'READ',
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function link(
        Tenant $tenant,
        Client $client,
        FiscalCategory $category,
    ): TenantFiscalCategoryLink {
        return TenantFiscalCategoryLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'fiscal_category_id' => $category->id,
            'status' => FiscalLinkStatus::Active,
            'coverage' => FiscalCoverage::Full,
            'activated_at' => now(),
        ]);
    }
}
