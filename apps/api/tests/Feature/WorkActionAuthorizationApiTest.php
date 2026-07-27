<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Enums\Work\TaskStatus;
use App\Http\Middleware\EnsureWorkRealMembership;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkProcess;
use App\Models\WorkProcessTemplate;
use App\Models\WorkTask;
use App\Models\WorkTaskEvidence;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Work\WorkEvidenceService;
use App\Support\CurrentTenant;
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
        $this->vault = new InMemoryWorkEvidenceStore;
        $this->app->instance(SecureObjectStore::class, $this->vault);
    }

    public function test_view_only_can_read_but_not_mutate_execute_catalog_process_export(): void
    {
        [$tenant, $actor] = $this->actorWithPermissions([TenantPermission::WorkView]);
        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $task = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
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
        $tenant = Tenant::factory()->create();
        $department = WorkDepartment::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fiscal',
            'code' => 'FISCAL',
        ]);

        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $tenant);
        [, $executor] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $tenant, $department->id);

        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $assigned = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
            'status' => TaskStatus::AFazer,
            'work_department_id' => $department->id,
            'assignee_membership_id' => $executor->memberships()->where('tenant_id', $tenant->id)->value('id'),
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
                $executor->memberships()->where('tenant_id', $tenant->id)->value('id'),
            );
    }

    public function test_processes_create_allows_store_and_denies_without_it(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $tenant);
        [, $creator] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $tenant);

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
        $tenant = Tenant::factory()->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkProcessesCreate,
        ], $tenant);
        [, $catalog] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkCatalogManage,
        ], $tenant);

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
        config()->set(
            'filesystems.disks.local.root',
            sys_get_temp_dir().'/kontivehub-work-exports-'.uniqid(),
        );
        $tenant = Tenant::factory()->create();
        [, $denied] = $this->actorWithPermissions([TenantPermission::WorkView], $tenant);
        [, $exporter] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkExportsCreate,
        ], $tenant);

        $this->authenticate($denied);
        $this->postJson('/api/v1/work/exports', ['filters' => []])->assertForbidden();

        $this->authenticate($exporter);
        $this->postJson('/api/v1/work/exports', ['filters' => []])
            ->assertCreated()
            ->assertJsonPath('data.status', 'READY');
    }

    public function test_evidence_download_requires_canonical_permission(): void
    {
        $tenant = Tenant::factory()->create();
        [, $denied] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
        ], $tenant);
        [, $downloader] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkEvidenceDownload,
        ], $tenant);

        $client = Client::factory()->forTenant($tenant)->create();
        $process = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $task = WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $process->id,
        ]);

        $this->authenticate($downloader);
        $bytes = '%PDF-1.4 work-evidence';
        $sha = hash('sha256', $bytes);
        $placeholder = $this->vault->put($bytes, []);
        $evidence = WorkTaskEvidence::query()->create([
            'tenant_id' => $tenant->id,
            'work_task_id' => $task->id,
            'original_filename' => 'comprovante.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => $sha,
            'vault_object_id' => $placeholder,
            'uploaded_by_membership_id' => $downloader->memberships()->where('tenant_id', $tenant->id)->value('id'),
        ]);
        $aad = WorkEvidenceService::aad(
            (int) $tenant->id,
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

    public function test_current_tenant_isolation_hides_foreign_work_resources(): void
    {
        [$tenantA, $actorA] = $this->actorWithPermissions([
            TenantPermission::WorkView,
            TenantPermission::WorkTasksExecute,
            TenantPermission::WorkAdminister,
        ]);
        $tenantB = Tenant::factory()->create();
        $clientB = Client::factory()->forTenant($tenantB)->create();
        $foreignProcess = WorkProcess::factory()->create([
            'tenant_id' => $tenantB->id,
            'client_id' => $clientB->id,
            'title' => 'Vazado',
        ]);
        $foreignTask = WorkTask::factory()->create([
            'tenant_id' => $tenantB->id,
            'work_process_id' => $foreignProcess->id,
            'lock_version' => 1,
        ]);
        $foreignTemplate = WorkProcessTemplate::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Template externo',
        ]);

        $this->authenticate($actorA);

        $this->getJson('/api/v1/work/processes/'.$foreignProcess->id)->assertNotFound();
        $this->getJson('/api/v1/work/tasks/'.$foreignTask->id)->assertNotFound();
        $this->postJson('/api/v1/work/tasks/'.$foreignTask->id.'/start', [
            'lock_version' => 1,
            'tenant_id' => $tenantB->id,
        ])->assertNotFound();
        $this->getJson('/api/v1/work/templates/'.$foreignTemplate->id)->assertNotFound();

        $keys = $this->getJson('/api/v1/work/processes?tenant_id='.$tenantB->id)
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
     * @return array{Tenant, User}
     */
    private function actorWithPermissions(
        array $permissions,
        ?Tenant $tenant = null,
        ?int $workDepartmentId = null,
    ): array {
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
