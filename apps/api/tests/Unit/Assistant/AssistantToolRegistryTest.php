<?php

namespace Tests\Unit\Assistant;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\ProcessTemplate;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Assistant\AssistantPendingApprovalStore;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
use DomainException;
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

        $this->bindAdminOffice();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ASSISTANT_TOOL_UNKNOWN');
        $registry->execute('serpro_consult', []);
    }

    public function test_list_tools_are_office_scoped(): void
    {
        [$admin, $office] = $this->bindAdminOffice();
        $other = Office::factory()->create();

        ProcessTemplate::factory()->create([
            'office_id' => $office->id,
            'name' => 'Modelo Local',
        ]);
        ProcessTemplate::factory()->create([
            'office_id' => $other->id,
            'name' => 'Modelo Externo',
        ]);
        WorkDepartment::factory()->create([
            'office_id' => $office->id,
            'name' => 'Fiscal Local',
            'code' => 'FIS',
        ]);
        WorkDepartment::factory()->create([
            'office_id' => $other->id,
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
        [$admin] = $this->bindAdminOffice();
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
        $this->assertDatabaseMissing('process_templates', ['name' => 'Nao Deve Persistir']);
    }

    public function test_create_with_approval_and_permission_persists(): void
    {
        [$admin, $office] = $this->bindAdminOffice();
        $registry = app(AssistantToolRegistry::class);
        $store = app(AssistantPendingApprovalStore::class);

        $token = $store->put(
            $office->id,
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
        $this->assertDatabaseHas('process_templates', [
            'office_id' => $office->id,
            'name' => 'Criado Via Tool',
        ]);
    }

    public function test_create_with_approval_without_manage_permission_is_forbidden(): void
    {
        $office = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $membership = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->where('user_id', $viewer->id)
            ->firstOrFail();
        app(CurrentOffice::class)->bind($viewer, $membership->load('office'));

        $store = app(AssistantPendingApprovalStore::class);
        $token = $store->put(
            $office->id,
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
        config(['features.canonical_multitenant_rbac.enabled' => true]);

        $office = Office::factory()->create();
        $user = User::factory()->create();
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys([TenantPermission::TenantDashboardView]);
        $membership = OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $user->id,
            'role' => OfficeRole::Viewer,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);
        app()->forgetInstance(TenantAuthorization::class);
        app(CurrentOffice::class)->clear();
        app(CurrentOffice::class)->bind($user, $membership->load(['office', 'permissionProfile']));

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

    /** @return array{User, Office} */
    private function bindAdminOffice(): array
    {
        $office = Office::factory()->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $membership = OfficeMembership::query()
            ->where('office_id', $office->id)
            ->where('user_id', $admin->id)
            ->firstOrFail();
        app(CurrentOffice::class)->bind($admin, $membership->load('office'));

        return [$admin, $office];
    }
}
