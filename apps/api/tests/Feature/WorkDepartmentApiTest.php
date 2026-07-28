<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WorkDepartmentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_list_accepts_http_boolean_strings_and_rejects_unknown_values(): void
    {
        [$admin, $tenant] = $this->actor();
        $active = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $inactive = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/work/departments?per_page=100&is_active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson('/api/v1/work/departments?per_page=100&is_active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inactive->id);

        $this->getJson('/api/v1/work/departments?is_active=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_department_requests_and_resources_preserve_contract(): void
    {
        [$admin, $tenant] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $foreign = WorkDepartment::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/departments', [
            'tenant_id' => $otherTenant->id,
            'name' => 'Não criar',
            'code' => 'NAO_CRIAR',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $created = $this->postJson('/api/v1/work/departments', [
            'name' => 'Departamento Fiscal',
            'code' => 'fiscal_sp',
            'color' => '#AABBCC',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'FISCAL_SP')
            ->assertJsonPath('data.is_active', true);

        $this->assertSame([
            'id',
            'name',
            'code',
            'color',
            'is_active',
            'created_at',
            'updated_at',
        ], array_keys($created->json('data')));

        $list = $this->getJson('/api/v1/work/departments?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonCount(1, 'data');
        $this->assertSame([
            'current_page',
            'last_page',
            'per_page',
            'total',
        ], array_keys($list->json('meta')));
        $this->assertArrayNotHasKey('links', $list->json());

        $departmentId = (int) $created->json('data.id');
        $this->patchJson("/api/v1/work/departments/{$departmentId}", [
            'code' => 'contabil',
            'color' => '#123456',
        ])->assertOk()
            ->assertJsonPath('data.code', 'CONTABIL');

        $this->patchJson("/api/v1/work/departments/{$foreign->id}", [
            'name' => 'Vazamento',
        ])->assertNotFound();

        $this->assertDatabaseHas('work_departments', [
            'id' => $departmentId,
            'tenant_id' => $tenant->id,
            'code' => 'CONTABIL',
        ]);
        $this->assertDatabaseMissing('work_departments', [
            'tenant_id' => $tenant->id,
            'name' => 'Não criar',
        ]);
    }

    public function test_membership_assignment_is_atomic_and_fail_closed(): void
    {
        [$admin, $tenant] = $this->actor();
        $target = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser)
            ->create();
        $membership = $target->memberships()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $active = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
        $inactive = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);
        $otherTenant = Tenant::factory()->create();
        $foreignUser = User::factory()
            ->forTenant($otherTenant, TenantRole::TenantUser)
            ->create();
        $foreignMembership = $foreignUser->memberships()
            ->where('tenant_id', $otherTenant->id)
            ->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/v1/work/departments/{$active->id}/assign-membership",
            ['membership_id' => $membership->id],
        )->assertOk()
            ->assertJsonPath('data.membership_id', $membership->id)
            ->assertJsonPath('data.work_department_id', $active->id);

        $this->postJson(
            "/api/v1/work/departments/{$inactive->id}/assign-membership",
            ['membership_id' => $membership->id],
        )->assertUnprocessable()
            ->assertJsonPath('code', 'work_department_inactive');

        $this->postJson(
            "/api/v1/work/departments/{$active->id}/assign-membership",
            [
                'tenant_id' => $otherTenant->id,
                'membership_id' => $foreignMembership->id,
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson(
            "/api/v1/work/departments/{$active->id}/assign-membership",
            ['membership_id' => $foreignMembership->id],
        )->assertNotFound();

        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $membership->id,
            'tenant_id' => $tenant->id,
            'work_department_id' => $active->id,
        ]);
        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $foreignMembership->id,
            'tenant_id' => $otherTenant->id,
            'work_department_id' => null,
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();
        $admin->forceFill([
            'selected_tenant_id' => $tenant->id,
        ])->saveQuietly();

        return [$admin, $tenant];
    }
}
