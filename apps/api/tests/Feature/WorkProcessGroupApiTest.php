<?php

namespace Tests\Feature;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkProcessTemplate;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkProcessGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_by_client_with_aggregates(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $alpha = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Alpha Contábil LTDA',
            'display_name' => 'Alpha',
        ]);
        $beta = Client::factory()->forTenant($tenant)->create([
            'legal_name' => 'Beta Serviços SA',
            'display_name' => 'Beta',
        ]);

        $p1 = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $alpha->id,
            'title' => 'DAS Alpha',
            'status' => ProcessStatus::AFazer,
            'due_date' => '2026-07-20',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $p1->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $p1->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);

        $p2 = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $alpha->id,
            'title' => 'DEFIS Alpha',
            'status' => ProcessStatus::EmProgresso,
            'due_date' => '2026-07-10',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $p2->id,
            'sort_order' => 1,
            'status' => TaskStatus::EmProgresso,
        ]);

        WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $beta->id,
            'title' => 'Folha Beta',
            'status' => ProcessStatus::AFazer,
            'due_date' => '2026-08-01',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/work/process-groups?group_by=client&sort=label&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $data = collect($response->json('data'));
        $alphaGroup = $data->firstWhere('key', (string) $alpha->id);
        $this->assertNotNull($alphaGroup);
        $this->assertSame('Alpha', $alphaGroup['label']);
        $this->assertSame($alpha->id, $alphaGroup['client']['id']);
        $this->assertSame(2, $alphaGroup['process_count']);
        $this->assertSame(1, $alphaGroup['client_count']);
        $this->assertSame(3, $alphaGroup['task_count']);
        $this->assertSame(2, $alphaGroup['open_task_count']);
        $this->assertSame(1, $alphaGroup['completed_task_count']);
        $this->assertSame(33, $alphaGroup['progress_percent']);
        $this->assertSame('2026-07-10', $alphaGroup['next_due_date']);
        $this->assertSame(1, $alphaGroup['status_counts'][ProcessStatus::AFazer->value]);
        $this->assertSame(1, $alphaGroup['status_counts'][ProcessStatus::EmProgresso->value]);
        $this->assertArrayNotHasKey('routine', $alphaGroup);
    }

    public function test_groups_by_routine_includes_manual_bucket(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $template = WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'PGDAS Mensal',
        ]);

        $fromTemplate = WorkProcess::factory()->fromTemplate()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => $template->id,
            'title' => 'Gerado PGDAS',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $fromTemplate->id,
        ]);

        $manual = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => null,
            'title' => 'Avulso sem rotina',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $manual->id,
        ]);

        // Mesmo título de rotina, mas sem template — NÃO deve criar grupo por título.
        WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => null,
            'title' => 'PGDAS Mensal',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/work/process-groups?group_by=routine&sort=label&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $data = collect($response->json('data'));
        $routine = $data->firstWhere('key', (string) $template->id);
        $manualGroup = $data->firstWhere('key', 'manual');

        $this->assertNotNull($routine);
        $this->assertSame('PGDAS Mensal', $routine['label']);
        $this->assertSame($template->id, $routine['routine']['id']);
        $this->assertSame(1, $routine['process_count']);

        $this->assertNotNull($manualGroup);
        $this->assertSame('Sem rotina', $manualGroup['label']);
        $this->assertNull($manualGroup['routine']);
        $this->assertSame(2, $manualGroup['process_count']);
        $this->assertArrayNotHasKey('client', $manualGroup);
    }

    public function test_sort_by_progress_percent_desc(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $low = Client::factory()->forTenant($tenant)->create([
            'display_name' => 'Baixo Progresso',
        ]);
        $mid = Client::factory()->forTenant($tenant)->create([
            'display_name' => 'Medio Progresso',
        ]);
        $high = Client::factory()->forTenant($tenant)->create([
            'display_name' => 'Alto Progresso',
        ]);
        $empty = Client::factory()->forTenant($tenant)->create([
            'display_name' => 'Sem Tarefas',
        ]);

        $lowProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $low->id,
            'title' => 'Low',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $lowProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $lowProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);

        $midProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $mid->id,
            'title' => 'Mid',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $midProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $midProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $midProcess->id,
            'sort_order' => 3,
            'status' => TaskStatus::Concluida,
        ]);

        $highProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $high->id,
            'title' => 'High',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $highProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $highProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::Dispensada,
        ]);

        WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $empty->id,
            'title' => 'Empty',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/work/process-groups?group_by=client&sort=progress_percent&direction=desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        $data = collect($response->json('data'));
        $this->assertSame([
            (string) $high->id,
            (string) $mid->id,
            (string) $low->id,
            (string) $empty->id,
        ], $data->pluck('key')->all());
        $this->assertSame([100, 67, 50, 0], $data->pluck('progress_percent')->all());
    }

    public function test_forbidden_without_work_view(): void
    {
        [$tenant, $actor] = $this->actorWithoutWorkView();
        Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/work/process-groups?group_by=client')
            ->assertForbidden();
    }

    public function test_multi_tenant_isolation_and_ignores_client_tenant_id(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create(['display_name' => 'Próprio']);
        $otherClient = Client::factory()->forTenant($otherTenant)->create(['display_name' => 'Externo']);

        WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $ownClient->id,
            'title' => 'Local',
        ]);
        WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'title' => 'Vazado',
        ]);

        Sanctum::actingAs($admin);

        $keys = $this->getJson('/api/v1/work/process-groups?group_by=client&tenant_id='.$otherTenant->id)
            ->assertOk()
            ->json('data.*.key');

        $this->assertSame([(string) $ownClient->id], $keys);
        $this->assertNotContains((string) $otherClient->id, $keys);
    }

    public function test_processes_include_tasks_default_and_omission(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Tarefa compacta',
            'status' => TaskStatus::AFazer,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'sort_order' => 2,
            'title' => 'Feita',
            'status' => TaskStatus::Concluida,
        ]);
        Sanctum::actingAs($admin);

        $withTasks = $this->getJson('/api/v1/work/processes')
            ->assertOk()
            ->json('data.0');
        $this->assertArrayHasKey('tasks', $withTasks);
        $this->assertCount(2, $withTasks['tasks']);
        $this->assertSame(2, $withTasks['task_count']);

        $withoutTasks = $this->getJson('/api/v1/work/processes?include_tasks=0')
            ->assertOk()
            ->json('data.0');
        $this->assertArrayNotHasKey('tasks', $withoutTasks);
        $this->assertSame(2, $withoutTasks['task_count']);
        $this->assertSame(1, $withoutTasks['open_task_count']);
        $this->assertSame(1, $withoutTasks['completed_task_count']);
        $this->assertSame(50, $withoutTasks['progress_percent']);
    }

    public function test_processes_filter_by_template_and_without_template(): void
    {
        [$admin, $tenant] = $this->actor(TenantRole::TenantAdmin);
        $client = Client::factory()->forTenant($tenant)->create();
        $template = WorkProcessTemplate::factory()->create(['tenant_id' => $tenant->id]);

        $withTemplate = WorkProcess::factory()->fromTemplate()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => $template->id,
            'title' => 'Com rotina',
        ]);
        $manual = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'work_process_template_id' => null,
            'title' => 'Manual',
        ]);
        Sanctum::actingAs($admin);

        $byTemplate = $this->getJson('/api/v1/work/processes?work_process_template_id='.$template->id.'&include_tasks=0')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$withTemplate->id], $byTemplate);

        $without = $this->getJson('/api/v1/work/processes?without_template=1&include_tasks=0')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$manual->id], $without);
    }

    public function test_processes_rejects_without_template_combined_with_template_id(): void
    {
        [$admin] = $this->actor(TenantRole::TenantAdmin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/work/processes?without_template=1&work_process_template_id=12')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['without_template']);
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }

    /** @return array{Tenant, User} */
    private function actorWithoutWorkView(): array
    {

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create();
        $actor->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::ClientsView]);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'role' => TenantRole::TenantUser,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);

        return [$tenant, $actor];
    }
}
