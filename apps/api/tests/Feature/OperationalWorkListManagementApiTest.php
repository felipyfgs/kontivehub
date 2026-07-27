<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalWorkListManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_tasks_start_and_partial_complete_failure_for_missing_evidence(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);
        $ready = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Sem evidência',
            'status' => TaskStatus::AFazer,
            'requires_evidence' => false,
            'lock_version' => 1,
        ]);
        $needsEvidence = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'title' => 'Com evidência',
            'status' => TaskStatus::EmProgresso,
            'requires_evidence' => true,
            'lock_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/tasks/bulk', [
            'items' => [
                ['id' => $ready->id, 'lock_version' => 1],
            ],
            'changes' => ['action' => 'start'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonCount(0, 'meta.failed');

        $this->assertDatabaseHas('work_tasks', [
            'id' => $ready->id,
            'status' => TaskStatus::EmProgresso->value,
        ]);

        $this->postJson('/api/v1/work/tasks/bulk', [
            'items' => [
                ['id' => $ready->fresh()->id, 'lock_version' => (int) $ready->fresh()->lock_version],
                ['id' => $needsEvidence->id, 'lock_version' => 1],
            ],
            'changes' => ['action' => 'complete'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonCount(1, 'meta.failed')
            ->assertJsonPath('meta.failed.0.id', $needsEvidence->id);

        $this->assertDatabaseHas('work_tasks', [
            'id' => $ready->id,
            'status' => TaskStatus::Concluida->value,
        ]);
        $this->assertDatabaseHas('work_tasks', [
            'id' => $needsEvidence->id,
            'status' => TaskStatus::EmProgresso->value,
        ]);
    }

    public function test_bulk_tasks_block_requires_reason_and_executor_can_claim(): void
    {
        [$operator, $tenant] = $this->actor(TenantRole::TenantUser);
        $department = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal',
            'code' => 'FISCAL',
        ]);
        $membership = $operator->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $membership->forceFill(['work_department_id' => $department->id])->saveQuietly();

        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);
        $task = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::EmProgresso,
            'work_department_id' => $department->id,
            'assignee_membership_id' => $membership->id,
            'lock_version' => 1,
        ]);
        $unclaimed = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => null,
            'lock_version' => 1,
        ]);
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/work/tasks/bulk', [
            'items' => [['id' => $task->id, 'lock_version' => 1]],
            'changes' => ['action' => 'block'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 0)
            ->assertJsonCount(1, 'meta.failed');

        $this->postJson('/api/v1/work/tasks/bulk', [
            'items' => [['id' => $task->id, 'lock_version' => 1]],
            'changes' => ['action' => 'block', 'reason' => 'Aguardando documento'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1);

        $this->assertDatabaseHas('work_tasks', [
            'id' => $task->id,
            'status' => TaskStatus::Impedida->value,
        ]);

        $this->postJson('/api/v1/work/tasks/bulk', [
            'items' => [['id' => $unclaimed->id, 'lock_version' => 1]],
            'changes' => ['action' => 'claim'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1);

        $this->assertDatabaseHas('work_tasks', [
            'id' => $unclaimed->id,
            'assignee_membership_id' => $membership->id,
        ]);
    }

    public function test_bulk_processes_archive_with_partial_failure(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $open = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::Concluido,
            'lock_version' => 1,
        ]);
        $stale = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::Concluido,
            'lock_version' => 3,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/processes/bulk', [
            'items' => [
                ['id' => $open->id, 'lock_version' => 1],
                ['id' => $stale->id, 'lock_version' => 1],
            ],
            'changes' => ['action' => 'archive'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonCount(1, 'meta.failed')
            ->assertJsonPath('meta.failed.0.id', $stale->id);

        $this->assertNotNull($open->fresh()->archived_at);
        $this->assertDatabaseHas('work_processes', [
            'id' => $open->id,
            'status' => ProcessStatus::Concluido->value,
        ]);
        $this->assertDatabaseHas('work_processes', [
            'id' => $stale->id,
            'status' => ProcessStatus::Concluido->value,
        ]);
        $this->assertNull($stale->fresh()->archived_at);
    }

    public function test_queue_sort_by_title_whitelist(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Zeta Ltda', 'display_name' => 'Zeta']);
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Zebra task',
            'status' => TaskStatus::AFazer,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'title' => 'Alpha task',
            'status' => TaskStatus::AFazer,
        ]);
        Sanctum::actingAs($admin);

        $asc = $this->getJson('/api/v1/work/queue?sort=title&direction=asc')
            ->assertOk()
            ->json('data');
        $this->assertSame('Alpha task', $asc[0]['title']);
        $this->assertSame('Zebra task', $asc[1]['title']);

        $desc = $this->getJson('/api/v1/work/queue?sort=title&direction=desc')
            ->assertOk()
            ->json('data');
        $this->assertSame('Zebra task', $desc[0]['title']);
        $this->assertSame('Alpha task', $desc[1]['title']);

        $this->getJson('/api/v1/work/queue?sort=not_a_column&direction=asc')
            ->assertUnprocessable();
    }

    public function test_bulk_processes_assign_ok(): void
    {
        [$operator, $tenant] = $this->actor(TenantRole::TenantUser);
        $assignee = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $assigneeMembership = $assignee->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
            'assignee_membership_id' => null,
            'lock_version' => 1,
        ]);
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/work/processes/bulk', [
            'items' => [['id' => $process->id, 'lock_version' => 1]],
            'changes' => [
                'action' => 'assign',
                'assignee_membership_id' => $assigneeMembership->id,
            ],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonCount(0, 'meta.failed');

        $this->assertDatabaseHas('work_processes', [
            'id' => $process->id,
            'assignee_membership_id' => $assigneeMembership->id,
        ]);
    }

    public function test_bulk_processes_assign_partial_lock_and_archive_policy_failure(): void
    {
        [$operator, $tenant] = $this->actor(TenantRole::TenantUser);
        $assignee = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $assigneeMembership = $assignee->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $client = Client::factory()->forTenant($tenant)->create();
        $ok = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
            'lock_version' => 1,
        ]);
        $stale = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
            'lock_version' => 4,
        ]);
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/work/processes/bulk', [
            'items' => [
                ['id' => $ok->id, 'lock_version' => 1],
                ['id' => $stale->id, 'lock_version' => 1],
            ],
            'changes' => [
                'action' => 'assign',
                'assignee_membership_id' => $assigneeMembership->id,
            ],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 1)
            ->assertJsonCount(1, 'meta.failed')
            ->assertJsonPath('meta.failed.0.id', $stale->id);

        $this->assertDatabaseHas('work_processes', [
            'id' => $ok->id,
            'assignee_membership_id' => $assigneeMembership->id,
        ]);
        $this->assertDatabaseHas('work_processes', [
            'id' => $stale->id,
            'assignee_membership_id' => null,
        ]);

        $this->postJson('/api/v1/work/processes/bulk', [
            'items' => [['id' => $ok->fresh()->id, 'lock_version' => (int) $ok->fresh()->lock_version]],
            'changes' => ['action' => 'archive'],
        ])->assertOk()
            ->assertJsonPath('meta.succeeded', 0)
            ->assertJsonCount(1, 'meta.failed')
            ->assertJsonPath('meta.failed.0.id', $ok->id);

        $this->assertDatabaseHas('work_processes', [
            'id' => $ok->id,
            'status' => ProcessStatus::EmProgresso->value,
        ]);
    }

    public function test_viewer_cannot_bulk_processes(): void
    {
        [$viewer, $tenant] = $this->actor(TenantRole::TenantUser, 'viewer');
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
            'lock_version' => 1,
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/v1/work/processes/bulk', [
            'items' => [['id' => $process->id, 'lock_version' => 1]],
            'changes' => ['action' => 'archive'],
        ])->assertForbidden();
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role, string $permissionProfile = 'operator'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role, $permissionProfile)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }
}
