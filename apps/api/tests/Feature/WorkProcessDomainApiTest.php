<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkProcessDomainApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_process_rejects_empty_tasks(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Sem tarefas',
            'competence' => '2026-07',
            'tasks' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['tasks']);
    }

    public function test_create_accepts_quarterly_and_annual_periods(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $quarterly = $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'DAS Trimestral',
            'competence' => '2026-T3',
            'due_date' => '2026-10-20',
            'target_due_date' => '2026-10-15',
            'tasks' => [['title' => 'Apurar']],
        ])->assertCreated()
            ->assertJsonPath('data.competence', '2026-T3')
            ->assertJsonPath('data.reference_period.type', 'QUARTERLY')
            ->assertJsonPath('data.reference_period.key', '2026-T3')
            ->assertJsonPath('data.reference_period.start', '2026-07-01')
            ->assertJsonPath('data.reference_period.end', '2026-09-30')
            ->assertJsonPath('data.due_date', '2026-10-20')
            ->assertJsonPath('data.target_due_date', '2026-10-15')
            ->assertJsonPath('data.status', ProcessStatus::AFazer->value);

        $this->assertDatabaseHas('work_processes', [
            'id' => $quarterly->json('data.id'),
            'competence' => '2026-T3',
            'reference_period_type' => 'QUARTERLY',
        ]);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'DEFIS Anual',
            'competence' => '2026',
            'tasks' => [['title' => 'Entregar']],
        ])->assertCreated()
            ->assertJsonPath('data.reference_period.type', 'ANNUAL')
            ->assertJsonPath('data.reference_period.key', '2026');
    }

    public function test_rejects_invalid_period(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Inválido',
            'competence' => '2026-T5',
            'tasks' => [['title' => 'X']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['competence']);
    }

    public function test_status_derives_from_task_transitions(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Derivado',
            'competence' => '2026-07',
            'tasks' => [
                ['title' => 'T1'],
                ['title' => 'T2'],
            ],
        ])->assertCreated();

        $processId = (int) $created->json('data.id');
        $t1 = (int) $created->json('data.tasks.0.id');
        $t1Lock = (int) $created->json('data.tasks.0.lock_version');
        $t2 = (int) $created->json('data.tasks.1.id');
        $t2Lock = (int) $created->json('data.tasks.1.lock_version');

        $this->postJson("/api/v1/work/tasks/{$t1}/start", ['lock_version' => $t1Lock])
            ->assertOk();
        $this->assertDatabaseHas('work_processes', [
            'id' => $processId,
            'status' => ProcessStatus::EmProgresso->value,
        ]);

        $t1Lock = (int) WorkTask::query()->findOrFail($t1)->lock_version;
        $this->postJson("/api/v1/work/tasks/{$t1}/block", [
            'lock_version' => $t1Lock,
            'reason' => 'Aguardando documento',
        ])->assertOk();
        $this->assertDatabaseHas('work_processes', [
            'id' => $processId,
            'status' => ProcessStatus::Impedido->value,
        ]);

        $t1Lock = (int) WorkTask::query()->findOrFail($t1)->lock_version;
        $this->postJson("/api/v1/work/tasks/{$t1}/resume", ['lock_version' => $t1Lock])
            ->assertOk();
        $t1Lock = (int) WorkTask::query()->findOrFail($t1)->lock_version;
        $this->postJson("/api/v1/work/tasks/{$t1}/complete", ['lock_version' => $t1Lock])
            ->assertOk();
        $this->postJson("/api/v1/work/tasks/{$t2}/dispense", [
            'lock_version' => $t2Lock,
            'justification' => 'Não aplicável',
        ])->assertOk();

        $this->assertDatabaseHas('work_processes', [
            'id' => $processId,
            'status' => ProcessStatus::Concluido->value,
        ]);
    }

    public function test_archive_requires_terminal_status_and_listing_excludes_archived(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $open = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
            'lock_version' => 1,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $open->id,
            'sort_order' => 1,
            'status' => TaskStatus::EmProgresso,
        ]);

        $this->postJson("/api/v1/work/processes/{$open->id}/archive", [
            'lock_version' => 1,
        ])->assertStatus(422);

        $done = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::Concluido,
            'lock_version' => 1,
            'title' => 'Concluído arquivável',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $done->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);

        $this->postJson("/api/v1/work/processes/{$done->id}/archive", [
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.status', ProcessStatus::Concluido->value)
            ->assertJsonPath('data.is_archived', true);

        $this->assertNotNull($done->fresh()->archived_at);
        $this->assertSame(ProcessStatus::Concluido, $done->fresh()->status);

        $list = $this->getJson('/api/v1/work/processes')->assertOk();
        $ids = collect($list->json('data'))->pluck('id')->all();
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($done->id, $ids);

        $withArchived = $this->getJson('/api/v1/work/processes?include_archived=1')->assertOk();
        $archivedIds = collect($withArchived->json('data'))->pluck('id')->all();
        $this->assertContains($done->id, $archivedIds);
    }

    public function test_coordinator_does_not_inherit_to_task_executor(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $coordinator = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $coordMembership = $coordinator->memberships()->where('tenant_id', $tenant->id)->firstOrFail();
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Com coordenador',
            'competence' => '2026-07',
            'assignee_membership_id' => $coordMembership->id,
            'tasks' => [['title' => 'Sem executor']],
        ])->assertCreated();

        $this->assertSame($coordMembership->id, $response->json('data.assignee_membership_id'));
        $this->assertNull($response->json('data.tasks.0.assignee_membership_id'));
    }

    public function test_rejects_assignee_from_other_tenant(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $other = Tenant::factory()->create();
        $foreign = User::factory()->forTenant($other, TenantRole::TenantUser)->create();
        $foreignMembership = $foreign->memberships()->where('tenant_id', $other->id)->firstOrFail();
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Tenant leak',
            'competence' => '2026-07',
            'assignee_membership_id' => $foreignMembership->id,
            'tasks' => [['title' => 'X']],
        ])->assertStatus(422);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }
}
