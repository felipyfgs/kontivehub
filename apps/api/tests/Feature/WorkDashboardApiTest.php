<?php

namespace Tests\Feature;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkExport;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WorkDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'filesystems.disks.local.root',
            sys_get_temp_dir().'/kontivehub-work-dashboard-'.uniqid(),
        );
    }

    public function test_calendar_preserves_interval_and_day_contracts_with_tenant_isolation(): void
    {
        [$actor, $tenant] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create([
            'display_name' => 'Cliente local',
        ]);
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $ownProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $ownClient->id,
        ]);
        $otherProcess = WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
        ]);
        $ownTask = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $ownProcess->id,
            'title' => 'Entrega local',
            'due_date' => '2026-07-30',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $ownProcess->id,
            'sort_order' => 2,
            'title' => 'Segunda entrega local',
            'due_date' => '2026-07-30',
        ]);
        $otherTask = WorkTask::factory()->create([
            'tenant_id' => $otherTenant->id,
            'work_process_id' => $otherProcess->id,
            'title' => 'Entrega externa',
            'due_date' => '2026-07-30',
        ]);

        Sanctum::actingAs($actor);

        $interval = $this->getJson(
            '/api/v1/work/calendar?from=2026-07-30&to=2026-07-30',
        )->assertOk()
            ->assertJsonPath('data.from', '2026-07-30')
            ->assertJsonPath('data.to', '2026-07-30')
            ->assertJsonPath('data.days.0.total', 2);

        $ids = collect($interval->json('data.days.0.items'))->pluck('id')->all();
        $this->assertContains($ownTask->id, $ids);
        $this->assertNotContains($otherTask->id, $ids);

        $day = $this->getJson(
            '/api/v1/work/calendar/day?date=2026-07-30&per_page=1&page=1',
        )->assertOk()
            ->assertJsonPath('meta', [
                'current_page' => 1,
                'last_page' => 2,
                'per_page' => 1,
                'total' => 2,
            ])
            ->assertJsonMissingPath('links');

        $this->assertCount(1, $day->json('data'));
        $this->assertNotSame($otherTask->id, $day->json('data.0.id'));
    }

    public function test_calendar_validates_filters_and_rejects_client_tenant_scope(): void
    {
        [$actor, $tenant] = $this->actor();
        Sanctum::actingAs($actor);

        $this->getJson(
            "/api/v1/work/calendar?from=2026-07-01&to=2026-07-31&tenant_id={$tenant->id}",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->getJson(
            '/api/v1/work/calendar?from=2026-07-01&to=2026-07-31&status=UNKNOWN',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson(
            '/api/v1/work/calendar?from=2026-07-01&to=2026-10-31',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_kpis_are_tenant_scoped_and_require_work_view(): void
    {
        [$actor, $tenant] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $ownProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $ownClient->id,
        ]);
        $otherProcess = WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $ownProcess->id,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $otherTenant->id,
            'work_process_id' => $otherProcess->id,
        ]);

        Sanctum::actingAs($actor);
        $this->getJson('/api/v1/work/kpis')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_open', 1);

        [, $denied] = $this->actorWithoutWorkView();
        Sanctum::actingAs($denied);
        $this->getJson('/api/v1/work/kpis')->assertForbidden();
    }

    public function test_export_resource_download_and_cross_tenant_boundary(): void
    {
        [$actor, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
        ]);

        Sanctum::actingAs($actor);

        $created = $this->postJson('/api/v1/work/exports', [
            'filters' => ['status' => TaskStatus::AFazer->value],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.row_count', 1);

        $this->assertSame([
            'id',
            'status',
            'filters_snapshot',
            'byte_size',
            'row_count',
            'error_message',
            'expires_at',
            'completed_at',
        ], array_keys($created->json('data')));

        $exportId = $created->json('data.id');
        $this->getJson("/api/v1/work/exports/{$exportId}")
            ->assertOk()
            ->assertJsonMissingPath('data.storage_path');

        $download = $this->get("/api/v1/work/exports/{$exportId}/download")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString(
            '"task_id","task_title"',
            $download->streamedContent(),
        );

        $otherTenant = Tenant::factory()->create();
        $otherMembership = TenantMembership::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);
        $otherExport = WorkExport::factory()->create([
            'tenant_id' => $otherTenant->id,
            'requested_by_membership_id' => $otherMembership->id,
        ]);
        $this->getJson("/api/v1/work/exports/{$otherExport->id}")
            ->assertNotFound();

        $this->postJson('/api/v1/work/exports', [
            'tenant_id' => $otherTenant->id,
            'filters' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_export_completes_across_multiple_database_chunks(): void
    {
        [$actor, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        WorkTask::factory()
            ->count(501)
            ->state(new Sequence(
                fn (Sequence $sequence): array => [
                    'sort_order' => $sequence->index + 1,
                ],
            ))
            ->create([
                'tenant_id' => $tenant->id,
                'work_process_id' => $process->id,
            ]);

        Sanctum::actingAs($actor);

        $created = $this->postJson('/api/v1/work/exports', [
            'filters' => [],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'READY')
            ->assertJsonPath('data.row_count', 501);

        $this->assertGreaterThan(0, $created->json('data.byte_size'));
    }

    /** @return array{User, Tenant} */
    private function actor(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
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
            'permission_profile_id' => $profile->id,
            'authorization_version' => 1,
            'is_active' => true,
        ]);

        return [$tenant, $actor];
    }
}
