<?php

namespace Tests\Unit\Assistant;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\AssistantToolNotAllowedException;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkProcessTemplate;
use App\Services\Assistant\AssistantPendingApprovalStore;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowlist_contains_expected_tools_and_rejects_unknown(): void
    {
        $registry = app(AssistantToolRegistry::class);

        foreach (AssistantToolRegistry::ALLOWLIST as $name) {
            $this->assertTrue($registry->isAllowlisted($name));
        }

        $this->assertFalse($registry->isAllowlisted('delete_everything'));
        $this->assertFalse($registry->isAllowlisted('serpro_consult'));

        $this->bindAdminTenant();

        $this->expectException(AssistantToolNotAllowedException::class);
        $this->expectExceptionMessage('ASSISTANT_TOOL_UNKNOWN');
        $registry->execute('serpro_consult', []);
    }

    public function test_list_tools_are_tenant_scoped(): void
    {
        [$admin, $tenant] = $this->bindAdminTenant();
        $other = Tenant::factory()->create();

        WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Modelo Local',
        ]);
        WorkProcessTemplate::factory()->create([
            'tenant_id' => $other->id,
            'name' => 'Modelo Externo',
        ]);
        WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal Local',
            'code' => 'FIS',
        ]);
        WorkDepartment::factory()->create([
            'tenant_id' => $other->id,
            'name' => 'Fiscal Externo',
            'code' => 'EXT',
        ]);

        $registry = app(AssistantToolRegistry::class);

        $templates = $registry->execute(AssistantToolRegistry::LIST_PROCESS_TEMPLATES, [], $admin);
        $names = collect($templates['result'])->pluck('name')->all();
        $this->assertContains('Modelo Local', $names);
        $this->assertNotContains('Modelo Externo', $names);

        $departments = $registry->execute(AssistantToolRegistry::LIST_WORK_DEPARTMENTS, [], $admin);
        $deptNames = collect($departments['result'])->pluck('name')->all();
        $this->assertContains('Fiscal Local', $deptNames);
        $this->assertNotContains('Fiscal Externo', $deptNames);

        $modules = $registry->execute(AssistantToolRegistry::LIST_MONITORING_MODULES, [], $admin);
        $keys = collect($modules['result'])->pluck('key')->all();
        $this->assertContains('PGDASD', $keys);
        $this->assertNotContains('https://evil.example', $keys);
    }

    public function test_create_without_approval_does_not_persist(): void
    {
        [$admin] = $this->bindAdminTenant();
        $registry = app(AssistantToolRegistry::class);

        $result = $registry->execute(
            AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
            ['name' => 'Nao Deve Persistir'],
            $admin,
            approved: false,
            conversationId: 1,
            toolCallId: 'call_1',
        );

        $this->assertSame('pending_approval', $result['status']);
        $this->assertArrayHasKey('approval_token', $result);
        $this->assertDatabaseMissing('work_process_templates', ['name' => 'Nao Deve Persistir']);
    }

    public function test_create_with_approval_and_permission_persists(): void
    {
        [$admin, $tenant] = $this->bindAdminTenant();
        $registry = app(AssistantToolRegistry::class);
        $store = app(AssistantPendingApprovalStore::class);

        $token = $store->put(
            $tenant->id,
            99,
            'call_ok',
            AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
            ['name' => 'Criado Via Tool', 'is_active' => true],
        );

        $result = $registry->execute(
            AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
            [],
            $admin,
            approved: true,
            approvalToken: $token,
            conversationId: 99,
        );

        $this->assertSame('ok', $result['status']);
        $this->assertDatabaseHas('work_process_templates', [
            'tenant_id' => $tenant->id,
            'name' => 'Criado Via Tool',
        ]);
    }

    public function test_create_with_approval_without_manage_permission_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $viewer->id)
            ->firstOrFail();
        app(CurrentTenant::class)->bind($viewer, $membership->load('tenant'));

        $store = app(AssistantPendingApprovalStore::class);
        $token = $store->put(
            $tenant->id,
            7,
            'call_forbidden',
            AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
            ['name' => 'Sem Permissao'],
        );

        $this->expectException(AuthorizationException::class);
        app(AssistantToolRegistry::class)->execute(
            AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
            [],
            $viewer,
            approved: true,
            approvalToken: $token,
            conversationId: 7,
        );
    }

    public function test_list_tools_require_work_view_permission(): void
    {

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::TenantDashboardView]);
        $membership = TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantRole::TenantUser,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);
        app()->forgetInstance(TenantAuthorization::class);
        app(CurrentTenant::class)->clear();
        app(CurrentTenant::class)->bind($user, $membership->load(['tenant', 'permissionProfile']));

        $registry = app(AssistantToolRegistry::class);

        foreach ([
            AssistantToolRegistry::LIST_PROCESS_TEMPLATES,
            AssistantToolRegistry::LIST_WORK_DEPARTMENTS,
            AssistantToolRegistry::LIST_MONITORING_MODULES,
        ] as $tool) {
            try {
                $registry->execute($tool, [], $user);
                $this->fail("Expected AuthorizationException for {$tool}");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(AuthorizationException::class);
        $registry->execute(AssistantToolRegistry::LIST_PROCESS_TEMPLATES, [], null);
    }

    /** @return array{User, Tenant} */
    private function bindAdminTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $admin->id)
            ->firstOrFail();
        app(CurrentTenant::class)->bind($admin, $membership->load('tenant'));

        return [$admin, $tenant];
    }
}
