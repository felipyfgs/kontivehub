<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\ProcessTemplate;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkProcessGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_by_client_with_aggregates(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $alpha = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Alpha Contábil LTDA',
            'display_name' => 'Alpha',
        ]);
        $beta = Client::factory()->forOffice($office)->create([
            'legal_name' => 'Beta Serviços SA',
            'display_name' => 'Beta',
        ]);

        $p1 = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $alpha->id,
            'title' => 'DAS Alpha',
            'status' => ProcessStatus::AFazer,
            'due_date' => '2026-07-20',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $p1->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $p1->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);

        $p2 = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $alpha->id,
            'title' => 'DEFIS Alpha',
            'status' => ProcessStatus::EmProgresso,
            'due_date' => '2026-07-10',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $p2->id,
            'sort_order' => 1,
            'status' => TaskStatus::EmProgresso,
        ]);

        OperationalProcess::factory()->create([
            'office_id' => $office->id,
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $template = ProcessTemplate::factory()->create([
            'office_id' => $office->id,
            'name' => 'PGDAS Mensal',
        ]);

        $fromTemplate = OperationalProcess::factory()->fromTemplate()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => $template->id,
            'title' => 'Gerado PGDAS',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $fromTemplate->id,
        ]);

        $manual = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => null,
            'title' => 'Avulso sem rotina',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $manual->id,
        ]);

        // Mesmo título de rotina, mas sem template — NÃO deve criar grupo por título.
        OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => null,
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $low = Client::factory()->forOffice($office)->create([
            'display_name' => 'Baixo Progresso',
        ]);
        $mid = Client::factory()->forOffice($office)->create([
            'display_name' => 'Medio Progresso',
        ]);
        $high = Client::factory()->forOffice($office)->create([
            'display_name' => 'Alto Progresso',
        ]);
        $empty = Client::factory()->forOffice($office)->create([
            'display_name' => 'Sem Tarefas',
        ]);

        $lowProcess = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $low->id,
            'title' => 'Low',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $lowProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $lowProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);

        $midProcess = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $mid->id,
            'title' => 'Mid',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $midProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $midProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $midProcess->id,
            'sort_order' => 3,
            'status' => TaskStatus::Concluida,
        ]);

        $highProcess = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $high->id,
            'title' => 'High',
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $highProcess->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $highProcess->id,
            'sort_order' => 2,
            'status' => TaskStatus::Dispensada,
        ]);

        OperationalProcess::factory()->create([
            'office_id' => $office->id,
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
        [$office, $actor] = $this->actorWithoutWorkView();
        Client::factory()->forOffice($office)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/work/process-groups?group_by=client')
            ->assertForbidden();
    }

    public function test_multi_tenant_isolation_and_ignores_client_office_id(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        $ownClient = Client::factory()->forOffice($office)->create(['display_name' => 'Próprio']);
        $otherClient = Client::factory()->forOffice($otherOffice)->create(['display_name' => 'Externo']);

        OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $ownClient->id,
            'title' => 'Local',
        ]);
        OperationalProcess::factory()->create([
            'office_id' => $otherOffice->id,
            'client_id' => $otherClient->id,
            'title' => 'Vazado',
        ]);

        Sanctum::actingAs($admin);

        $keys = $this->getJson('/api/v1/work/process-groups?group_by=client&office_id='.$otherOffice->id)
            ->assertOk()
            ->json('data.*.key');

        $this->assertSame([(string) $ownClient->id], $keys);
        $this->assertNotContains((string) $otherClient->id, $keys);
    }

    public function test_processes_include_tasks_default_and_omission(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'Tarefa compacta',
            'status' => TaskStatus::AFazer,
        ]);
        OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $template = ProcessTemplate::factory()->create(['office_id' => $office->id]);

        $withTemplate = OperationalProcess::factory()->fromTemplate()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => $template->id,
            'title' => 'Com rotina',
        ]);
        $manual = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => null,
            'title' => 'Manual',
        ]);
        Sanctum::actingAs($admin);

        $byTemplate = $this->getJson('/api/v1/work/processes?process_template_id='.$template->id.'&include_tasks=0')
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
        [$admin] = $this->actor(OfficeRole::Admin);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/work/processes?without_template=1&process_template_id=12')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['without_template']);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        return [$user, $office];
    }

    /** @return array{Office, User} */
    private function actorWithoutWorkView(): array
    {
        config(['features.canonical_multitenant_rbac.enabled' => true]);

        $office = Office::factory()->create();
        $actor = User::factory()->create();
        $actor->forceFill(['selected_office_id' => $office->id])->saveQuietly();
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys([TenantPermission::ClientsView]);
        OfficeMembership::factory()->create([
            'office_id' => $office->id,
            'user_id' => $actor->id,
            'role' => OfficeRole::Viewer,
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);

        return [$office, $actor];
    }
}
