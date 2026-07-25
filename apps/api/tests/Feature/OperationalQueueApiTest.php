<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperationalQueueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_todas_returns_four_board_statuses_and_excludes_dispensada(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $aFazer = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 1,
            'title' => 'A fazer',
            'status' => TaskStatus::AFazer,
        ]);
        $emProgresso = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 2,
            'title' => 'Em progresso',
            'status' => TaskStatus::EmProgresso,
        ]);
        $impedida = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 3,
            'title' => 'Impedida',
            'status' => TaskStatus::Impedida,
            'block_reason' => 'Aguardando cliente',
        ]);
        $concluida = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 4,
            'title' => 'Concluída',
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
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
    }

    public function test_tab_open_excludes_concluida_and_dispensada(): void
    {
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $aFazer = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::AFazer,
        ]);
        $concluida = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
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
        [$admin, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => ProcessStatus::EmProgresso,
        ]);

        $concluida = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 1,
            'status' => TaskStatus::Concluida,
        ]);
        $dispensada = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::Dispensada,
        ]);
        $aFazer = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
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

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $user->forceFill(['selected_office_id' => $office->id])->saveQuietly();

        return [$user, $office];
    }
}
