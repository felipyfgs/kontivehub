<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\TaskStatus;
use App\Http\Middleware\EnsureWorkRealMembership;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use App\Models\ProcessTemplate;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Work\OperationalEvidenceService;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * C0 work-action-authorization: 403 vs happy path pelas chaves TenantPermission canônicas.
 */
final class WorkActionAuthorizationApiTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryWorkEvidenceStore $vault;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.canonical_multitenant_rbac.enabled' => true]);
        $this->vault = new InMemoryWorkEvidenceStore;
        $this->app->instance(SecureObjectStore::class, $this->vault);
    }

    public function test_view_only_can_read_but_not_mutate_execute_catalog_process_export(): void
    {
        [$office, $actor] = $this->actorWithPermissions([TenantPermission::WorkView]);
        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $task = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
            'lock_version' => 1,
        ]);
        $this->authenticate($actor);

        $this->getJson('/api/v1/work/processes')->assertOk();
        $this->getJson('/api/v1/work/tasks/'.$task->id)->assertOk();

        $this->postJson('/api/v1/work/tasks/'.$task->id.'/start', ['lock_version' => 1])
            ->assertForbidden();
        $this->postJson('/api/v1/work/tasks/'.$task->id.'/claim', ['lock_version' => 1])
            ->assertForbidden();
        $this->postJson('/api/v1/work/processes', [
            'client_id' => $client->id,
            'title' => 'Negado',
            'competence' => '2026-07',
            'tasks' => [['title' => 'X']],
        ])->assertForbidden();
        $this->postJson('/api/v1/work/departments', [
            'name' => 'Fiscal Negado',
            'code' => 'NEGADO',
        ])->assertForbidden();
        $this->postJson('/api/v1/work/exports', ['filters' => []])
            ->assertForbidden();
    }

    public function test_tasks_execute_allows_transition_and_claim_and_denies_without_it(): void
    {
        $office = Office::factory()->create();
        $department = WorkDepartment::factory()->create([
            'office_id' => $office->id,
            'name' => 'Fiscal',
            'code' => 'FISCAL',
        ]);

        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $office);
        [, $executor] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $office, $department->id);

        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $assigned = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => $executor->memberships()->where('office_id', $office->id)->value('id'),
            'lock_version' => 1,
        ]);
        $unclaimed = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
            'sort_order' => 2,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => null,
            'lock_version' => 1,
        ]);

        $this->authenticate($denied);
        $this->postJson('/api/v1/work/tasks/'.$assigned->id.'/start', ['lock_version' => 1])
            ->assertForbidden();
        $this->postJson('/api/v1/work/tasks/'.$unclaimed->id.'/claim', ['lock_version' => 1])
            ->assertForbidden();

        $this->authenticate($executor);
        $this->postJson('/api/v1/work/tasks/'.$assigned->id.'/start', ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', TaskStatus::EmProgresso->value);
        $this->postJson('/api/v1/work/tasks/'.$unclaimed->id.'/claim', ['lock_version' => 1])
            ->assertOk()
            ->assertJsonPath(
                'data.assignee_membership_id',
                $executor->memberships()->where('office_id', $office->id)->value('id'),
            );
    }

    public function test_processes_create_allows_store_and_denies_without_it(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $office);
        [, $creator] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $office);

        $payload = [
            'client_id' => $client->id,
            'title' => 'Processo canônico',
            'competence' => '2026-07',
            'tasks' => [['title' => 'Apurar']],
        ];

        $this->authenticate($denied);
        $this->postJson('/api/v1/work/processes', $payload)->assertForbidden();

        $this->authenticate($creator);
        $this->postJson('/api/v1/work/processes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Processo canônico');
    }

    public function test_catalog_manage_allows_department_and_template_create(): void
    {
        $office = Office::factory()->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $office);
        [, $catalog] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkCatalogManage,
        ], $office);

        $this->authenticate($denied);
        $this->postJson('/api/v1/work/departments', [
            'name' => 'Sem catálogo',
            'code' => 'NOCAT',
        ])->assertForbidden();
        $this->postJson('/api/v1/work/templates', [
            'name' => 'Rotina negada',
            'tasks' => [['sort_order' => 1, 'title' => 'Passo']],
        ])->assertForbidden();

        $this->authenticate($catalog);
        $this->postJson('/api/v1/work/departments', [
            'name' => 'Fiscal Canônico',
            'code' => 'FISCAN',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'FISCAN');
        $this->postJson('/api/v1/work/templates', [
            'name' => 'Rotina canônica',
            'tasks' => [['sort_order' => 1, 'title' => 'Passo']],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Rotina canônica');
    }

    public function test_exports_create_allows_export_and_denies_without_it(): void
    {
        $office = Office::factory()->create();
        [, $denied] = $this->actorWithPermissions([TenantPermission::WorkView], $office);
        [, $exporter] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkExportsCreate,
        ], $office);

        $this->authenticate($denied);
        $this->postJson('/api/v1/work/exports', ['filters' => []])->assertForbidden();

        $this->authenticate($exporter);
        $this->postJson('/api/v1/work/exports', ['filters' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', 'READY');
    }

    public function test_evidence_download_requires_canonical_permission(): void
    {
        $office = Office::factory()->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $office);
        [, $downloader] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkEvidenceDownload,
        ], $office);

        $client = Client::factory()->forOffice($office)->create();
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);
        $task = OperationalTask::factory()->create([
            'office_id' => $office->id,
            'operational_process_id' => $process->id,
        ]);

        $this->authenticate($downloader);
        $bytes = '%PDF-1.4 work-evidence';
        $sha = hash('sha256', $bytes);
        $placeholder = $this->vault->put($bytes, []);
        $evidence = OperationalTaskEvidence::query()->create([
            'office_id' => $office->id,
            'operational_task_id' => $task->id,
            'original_filename' => 'comprovante.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => $sha,
            'vault_object_id' => $placeholder,
            'uploaded_by_membership_id' => $downloader->memberships()->where('office_id', $office->id)->value('id'),
        ]);
        $aad = OperationalEvidenceService::aad(
            (int) $office->id,
            (int) $task->id,
            (string) $evidence->id,
            $sha,
        );
        $objectId = $this->vault->put($bytes, $aad);
        $evidence->forceFill(['vault_object_id' => $objectId])->save();

        $this->authenticate($denied);
        $this->getJson('/api/v1/work/tasks/'.$task->id.'/evidences/'.$evidence->id.'/download')
            ->assertForbidden();

        $this->authenticate($downloader);
        $this->get('/api/v1/work/tasks/'.$task->id.'/evidences/'.$evidence->id.'/download')
            ->assertOk();
    }

    public function test_current_office_isolation_hides_foreign_work_resources(): void
    {
        [$officeA, $actorA] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
        ]);
        $officeB = Office::factory()->create();
        $clientB = Client::factory()->forOffice($officeB)->create();
        $foreignProcess = OperationalProcess::factory()->create([
            'office_id' => $officeB->id,
            'client_id' => $clientB->id,
            'title' => 'Vazado',
        ]);
        $foreignTask = OperationalTask::factory()->create([
            'office_id' => $officeB->id,
            'operational_process_id' => $foreignProcess->id,
            'lock_version' => 1,
        ]);
        $foreignTemplate = ProcessTemplate::factory()->create([
            'office_id' => $officeB->id,
            'name' => 'Template externo',
        ]);

        $this->authenticate($actorA);

        $this->getJson('/api/v1/work/processes/'.$foreignProcess->id)->assertNotFound();
        $this->getJson('/api/v1/work/tasks/'.$foreignTask->id)->assertNotFound();
        $this->postJson('/api/v1/work/tasks/'.$foreignTask->id.'/start', [
            'lock_version' => 1,
            'office_id' => $officeB->id,
        ])->assertNotFound();
        $this->getJson('/api/v1/work/templates/'.$foreignTemplate->id)->assertNotFound();

        $keys = $this->getJson('/api/v1/work/processes?office_id='.$officeB->id)
            ->assertOk()
            ->json('data.*.id');
        $this->assertNotContains($foreignProcess->id, $keys);
    }

    public function test_mutate_work_routes_require_ensure_work_real_membership(): void
    {
        $mutate = collect(config('work_route_matrix.mutate', []));
        $this->assertNotEmpty($mutate);

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            if (! $route instanceof RoutingRoute) {
                continue;
            }
            $uri = ' /'.$route->uri();
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $signature = strtoupper($method).' /api/'.$route->uri();
                $signature = preg_replace('#\{([^}/]+)\}#', '{$1}', $signature) ?? $signature;
                if (! $mutate->contains($signature) && ! $this->matrixMatches($mutate, $method, $route->uri())) {
                    continue;
                }
                $middlewares = $route->gatherMiddleware();
                if (! in_array(EnsureWorkRealMembership::class, $middlewares, true)) {
                    $missing[] = $signature;
                }
            }
        }

        $this->assertSame([], $missing, 'Rotas mutate Work sem EnsureWorkRealMembership: '.implode(', ', $missing));
    }

    /**
     * @param  Collection<int, string>  $mutate
     */
    private function matrixMatches($mutate, string $method, string $uri): bool
    {
        $candidate = strtoupper($method).' /api/'.$uri;

        return $mutate->contains(function (string $entry) use ($candidate): bool {
            $pattern = preg_replace('#\{[^}]+\}#', '[^/]+', $entry) ?? $entry;
            $pattern = '#^'.str_replace('/', '\/', $pattern).'$#';

            return (bool) preg_match($pattern, $candidate);
        });
    }

    /**
     * @param  list<TenantPermission>  $permissions
     * @return array{Office, User}
     */
    private function actorWithPermissions(
        array $permissions,
        ?Office $office = null,
        ?int $workDepartmentId = null,
    ): array {
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

final class InMemoryWorkEvidenceStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    private int $sequence = 0;

    public function put(string $plaintext, array $metadata = []): string
    {
        $this->sequence++;
        $id = 'work-ev-'.$this->sequence;
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        if (! isset($this->objects[$objectId])) {
            throw new RuntimeException('Objeto não encontrado.');
        }

        return $this->objects[$objectId];
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return isset($this->objects[$objectId]);
    }
}
