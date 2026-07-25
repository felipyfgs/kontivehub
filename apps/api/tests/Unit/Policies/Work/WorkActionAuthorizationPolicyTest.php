<?php

namespace Tests\Unit\Policies\Work;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalExport;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\ProcessTemplate;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
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
        config(['features.canonical_multitenant_rbac.enabled' => true]);
    }

    public function test_task_abilities_map_to_canonical_permissions(): void
    {
        $office = Office::factory()->create();
        $department = WorkDepartment::factory()->create(['office_id' => $office->id]);
        [, $viewer] = $this->actor([TenantPermission::WorkView], $office);
        [, $executor] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $office, $department->id);
        [, $admin] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
            TenantPermission::WorkEvidenceDownload,
        ], $office);

        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $executorMembershipId = (int) $executor->memberships()->where('office_id', $office->id)->value('id');
        $assigned = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => $executorMembershipId,
        ]);
        $unclaimed = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
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
        $office = Office::factory()->create();
        [, $viewer] = $this->actor([TenantPermission::WorkView], $office);
        [, $creator] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $office);
        [, $catalog] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkCatalogManage,
        ], $office);
        [, $exporter] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkExportsCreate,
        ], $office);

        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $template = ProcessTemplate::factory()->create(['office_id' => $office->id]);
        $department = WorkDepartment::factory()->create(['office_id' => $office->id]);
        $export = OperationalExport::factory()->create([
            'office_id' => $office->id,
            'requested_by_membership_id' => $exporter->memberships()->where('office_id', $office->id)->value('id'),
        ]);

        $this->authenticate($viewer);
        $this->assertTrue(Gate::forUser($viewer)->allows('viewAny', OperationalProcess::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', OperationalProcess::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', ProcessTemplate::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', WorkDepartment::class));
        $this->assertFalse(Gate::forUser($viewer)->allows('create', OperationalExport::class));

        $this->authenticate($creator);
        $this->assertTrue(Gate::forUser($creator)->allows('create', OperationalProcess::class));
        $this->assertTrue(Gate::forUser($creator)->allows('update', $process));
        $this->assertFalse(Gate::forUser($creator)->allows('create', ProcessTemplate::class));
        $this->assertFalse(Gate::forUser($creator)->allows('create', OperationalExport::class));

        $this->authenticate($catalog);
        $this->assertTrue(Gate::forUser($catalog)->allows('create', ProcessTemplate::class));
        $this->assertTrue(Gate::forUser($catalog)->allows('update', $template));
        $this->assertTrue(Gate::forUser($catalog)->allows('create', WorkDepartment::class));
        $this->assertTrue(Gate::forUser($catalog)->allows('update', $department));
        $this->assertFalse(Gate::forUser($catalog)->allows('create', OperationalProcess::class));

        $this->authenticate($exporter);
        $this->assertTrue(Gate::forUser($exporter)->allows('create', OperationalExport::class));
        $this->assertTrue(Gate::forUser($exporter)->allows('view', $export));
        $this->assertTrue(Gate::forUser($exporter)->allows('download', $export));
    }

    public function test_foreign_office_target_is_denied(): void
    {
        [$officeA, $actor] = $this->actor([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
            TenantPermission::WorkCatalogManage,
            TenantPermission::WorkProcessesCreate,
            TenantPermission::WorkExportsCreate,
            TenantPermission::WorkEvidenceDownload,
        ]);
        $officeB = Office::factory()->create();
        $clientB = Client::factory()->forOffice($officeB)->create();
        $foreignProcess = OperationalProcess::factory()->create([
            'office_id' => $officeB->id,
            'client_id' => $clientB->id,
        ]);
        $foreignTask = OperationalTask::factory()->create([
            'office_id' => $officeB->id,
            'operational_process_id' => $foreignProcess->id,
        ]);
        $foreignTemplate = ProcessTemplate::factory()->create(['office_id' => $officeB->id]);
        $foreignExport = OperationalExport::factory()->create(['office_id' => $officeB->id]);

        $this->authenticate($actor);

        $this->assertFalse(Gate::forUser($actor)->allows('view', $foreignProcess));
        $this->assertFalse(Gate::forUser($actor)->allows('transition', $foreignTask));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $foreignTemplate));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $foreignExport));
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return array{Office, User}
     */
    private function actor(array $permissions, ?Office $office = null, ?int $workDepartmentId = null): array
    {
        $office ??= Office::factory()->create();
        $actor = User::factory()->create();
        $actor->forceFill(['selected_office_id' => $office->id])->saveQuietly();
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys($permissions);
        OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $actor->id,
            'role' => OfficeRole::Operator,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'work_department_id' => $workDepartmentId,
            'is_active' => true,
        ]);

        return [$office, $actor];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
