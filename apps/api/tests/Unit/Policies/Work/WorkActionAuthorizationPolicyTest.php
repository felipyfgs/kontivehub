<?php

namespace Tests\Unit\Policies\Work;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkExport;
use App\Models\WorkProcess;
use App\Models\WorkProcessTemplate;
use App\Models\WorkTask;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Confirma mapeamento ação → TenantPermission nas policies Work (autoridade canônica).
 */
final class WorkActionAuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_task_abilities_map_to_canonical_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $department = WorkDepartment::factory()->create(['tenant_id' => $tenant->id]);
        [, $viewer] = $this->actor([TenantPermission::WorkView], $tenant);
        [, $executor] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $tenant, $department->id);
        [, $admin] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
            TenantPermission::WorkEvidenceDownload,
        ], $tenant);

        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $executorMembershipId = (int) $executor->memberships()->where('tenant_id', $tenant->id)->value('id');
        $assigned = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => $executorMembershipId,
        ]);
        $unclaimed = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => null,
        ]);

        $this->authenticate($viewer);
        $this->assertTrue(Gate::forUser($viewer)->allows('view', $assigned));
        $this->assertFalse(Gate::forUser($viewer)->allows('transition', $assigned));
        $this->assertFalse(Gate::forUser($viewer)->allows('claim', $unclaimed));
        $this->assertFalse(Gate::forUser($viewer)->allows('downloadEvidence', $assigned));

        $this->authenticate($executor);
        $this->assertTrue(Gate::forUser($executor)->allows('transition', $assigned));
        $this->assertTrue(Gate::forUser($executor)->allows('claim', $unclaimed));
        $this->assertFalse(Gate::forUser($executor)->allows('assign', $assigned));
        $this->assertFalse(Gate::forUser($executor)->allows('downloadEvidence', $assigned));

        $this->authenticate($admin);
        $this->assertTrue(Gate::forUser($admin)->allows('assign', $assigned));
        $this->assertTrue(Gate::forUser($admin)->allows('dispense', $assigned));
        $this->assertTrue(Gate::forUser($admin)->allows('downloadEvidence', $assigned));
    }

    public function test_process_catalog_export_abilities_map_to_canonical_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        [, $viewer] = $this->actor([TenantPermission::WorkView], $tenant);
        [, $creator] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $tenant);
        [, $catalog] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkCatalogManage,
        ], $tenant);
        [, $exporter] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkExportsCreate,
        ], $tenant);

        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $template = WorkProcessTemplate::factory()->create(['tenant_id' => $tenant->id]);
        $department = WorkDepartment::factory()->create(['tenant_id' => $tenant->id]);
        $export = WorkExport::factory()->create([
            'tenant_id' => $tenant->id,
            'requested_by_membership_id' => $exporter->memberships()->where('tenant_id', $tenant->id)->value('id'),
        ]);

        $this->authenticate($viewer);
        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', WorkProcess::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkProcess::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkProcessTemplate::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkDepartment::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkExport::class));

        $this->authenticate($creator);
        $this->assertTrue(Gate::forUser($creator)->allows('create', WorkProcess::class));
        $this->assertTrue(Gate::forUser($creator)->allows('update', $process));
        $this->assertFalse(Gate::forUser($creator)->allows('create', WorkProcessTemplate::class));
        $this->assertFalse(Gate::forUser($creator)->allows('create', WorkExport::class));

        $this->authenticate($catalog);
        $this->assertTrue(Gate::forUser($catalog)->allows('create', WorkProcessTemplate::class));
        $this->assertTrue(Gate::forUser($catalog)->allows('update', $template));
        $this->assertTrue(Gate::forUser($catalog)->allows('create', WorkDepartment::class));
        $this->assertTrue(Gate::forUser($catalog)->allows('update', $department));
        $this->assertFalse(Gate::forUser($catalog)->allows('create', WorkProcess::class));

        $this->authenticate($exporter);
        $this->assertTrue(Gate::forUser($exporter)->allows('create', WorkExport::class));
        $this->assertTrue(Gate::forUser($exporter)->allows('view', $export));
        $this->assertTrue(Gate::forUser($exporter)->allows('download', $export));
    }

    public function test_foreign_tenant_target_is_denied(): void
    {
        [$tenantA, $actor] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
            TenantPermission::WorkCatalogManage,
            TenantPermission::WorkProcessesCreate,
            TenantPermission::WorkExportsCreate,
            TenantPermission::WorkEvidenceDownload,
        ]);
        $tenantB = Tenant::factory()->create();
        $clientB = Client::factory()->forTenant($tenantB)->create();
        $foreignProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenantB->id,
            'client_id' => $clientB->id,
        ]);
        $foreignTask = WorkTask::factory()->create([
            'tenant_id' => $tenantB->id,
            'work_process_id' => $foreignProcess->id,
        ]);
        $foreignTemplate = WorkProcessTemplate::factory()->create(['tenant_id' => $tenantB->id]);
        $foreignExport = WorkExport::factory()->create(['tenant_id' => $tenantB->id]);

        $this->authenticate($actor);

        $this->assertFalse(Gate::forUser($actor)->allows('view', $foreignProcess));
        $this->assertFalse(Gate::forUser($actor)->allows('transition', $foreignTask));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $foreignTemplate));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $foreignExport));
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return array{Tenant, User}
     */
    private function actor(array $permissions, ?Tenant $tenant = null, ?int $workDepartmentId = null): array
    {
        $tenant ??= Tenant::factory()->create();
        $actor = User::factory()->create();
        $actor->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys($permissions);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'role' => TenantRole::TenantUser,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'work_department_id' => $workDepartmentId,
            'is_active' => true,
        ]);

        return [$tenant, $actor];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
