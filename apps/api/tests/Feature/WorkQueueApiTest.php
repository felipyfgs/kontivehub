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

class WorkQueueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_todas_returns_four_board_statuses_and_excludes_dispensada(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $aFazer = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'A fazer',
            'status' => TaskStatus::AFazer,
        ]);
        $emProgresso = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'title' => 'Em progresso',
            'status' => TaskStatus::EmProgresso,
        ]);
        $impedida = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 3,
            'title' => 'Impedida',
            'status' => TaskStatus::Impedida,
            'block_reason' => 'Aguardando cliente',
        ]);
        $concluida = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 4,
            'title' => 'Concluída',
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 5,
            'title' => 'Dispensada',
            'status' => TaskStatus::Dispensada,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/work/queue?tab=todas&per_page=100')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $statuses = collect($response->json('data'))->pluck('status')->unique()->sort()->values()->all();

        $this->assertContains($aFazer->id, $ids);
        $this->assertContains($emProgresso->id, $ids);
        $this->assertContains($impedida->id, $ids);
        $this->assertContains($concluida->id, $ids);
        $this->assertNotContains($dispensada->id, $ids);

        $this->assertSame([
            TaskStatus::AFazer->value,
            TaskStatus::Concluida->value,
            TaskStatus::EmProgresso->value,
            TaskStatus::Impedida->value,
        ], $statuses);

        $this->assertSame(4, $response->json('meta.total'));
        $this->assertSame([
            'current_page',
            'last_page',
            'per_page',
            'total',
        ], array_keys($response->json('meta')));
        $this->assertArrayNotHasKey('links', $response->json());
    }

    public function test_tab_open_excludes_concluida_and_dispensada(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $aFazer = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        $concluida = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 3,
            'status' => TaskStatus::Dispensada,
        ]);

        Sanctum::actingAs($admin);

        $ids = collect($this->getJson('/api/v1/work/queue?tab=open')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($aFazer->id, $ids);
        $this->assertNotContains($concluida->id, $ids);
        $this->assertNotContains($dispensada->id, $ids);
    }

    public function test_tab_concluidas_includes_dispensada(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $concluida = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::Dispensada,
        ]);
        $aFazer = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 3,
            'status' => TaskStatus::AFazer,
        ]);

        Sanctum::actingAs($admin);

        $ids = collect($this->getJson('/api/v1/work/queue?tab=concluidas')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($concluida->id, $ids);
        $this->assertContains($dispensada->id, $ids);
        $this->assertNotContains($aFazer->id, $ids);
    }

    public function test_tab_sem_responsavel_defaults_to_tenant_scope_for_operator(): void
    {
        [$operator, $tenant] = $this->actor(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);
        $otherProcess = WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'status' => ProcessStatus::EmProgresso,
        ]);
        $unassigned = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
            'assignee_membership_id' => null,
        ]);
        $assigned = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
            'assignee_membership_id' => $operator->memberships()
                ->where('tenant_id', $tenant->id)
                ->value('id'),
        ]);
        $completed = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 3,
            'status' => TaskStatus::Concluida,
            'assignee_membership_id' => null,
        ]);
        $foreign = WorkTask::factory()->create([
            'tenant_id' => $otherTenant->id,
            'work_process_id' => $otherProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
            'assignee_membership_id' => null,
        ]);

        Sanctum::actingAs($operator);

        $ids = collect($this->getJson('/api/v1/work/queue?tab=sem_responsavel')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame([$unassigned->id], $ids);
        $this->assertNotContains($assigned->id, $ids);
        $this->assertNotContains($completed->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
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
