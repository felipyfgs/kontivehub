<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Jobs\Communication\AdvanceFlowRunJob;
use App\Jobs\Communication\CorrelateFlowEventJob;
use App\Models\CommunicationEvent;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationFlowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([CommunicationEventCommitted::class]);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.flows.enabled' => true,
        ]);
    }

    public function test_manage_flows_permission_catalog_and_flag_default_false(): void
    {
        $this->assertSame('communication.manage_flows', TenantPermission::CommunicationManageFlows->value);
        $this->assertSame('Gerenciar fluxos de comunicação', TenantPermission::CommunicationManageFlows->label());
        $this->assertContains(
            TenantPermission::CommunicationManageFlows->value,
            TenantPermission::orderedValues(),
        );

        config()->set('communication.flows.enabled', null);
        putenv('COMMUNICATION_FLOWS_ENABLED');
        $_ENV['COMMUNICATION_FLOWS_ENABLED'] = 'false';
        $_SERVER['COMMUNICATION_FLOWS_ENABLED'] = 'false';
        $this->assertFalse(filter_var(env('COMMUNICATION_FLOWS_ENABLED', false), FILTER_VALIDATE_BOOL));
    }

    public function test_create_flow_starts_paused_with_draft(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $response = $this->postJson('/api/v1/communication/flows', ['name' => 'Triagem'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Triagem')
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.lock_version', 1);

        $flowId = (int) $response->json('data.id');
        $draft = CommunicationFlowDraft::query()->where('flow_id', $flowId)->first();
        $this->assertNotNull($draft);
        $this->assertSame(1, (int) $draft->lock_version);
        $raw = $draft->getAttributes()['graph_encrypted'] ?? null;
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('"nodes"', $raw);
    }

    public function test_mutations_require_manage_flows_not_view_or_inboxes(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $inboxManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $flowManager = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();

        $this->assignProfile($viewer, $tenant, [TenantPermission::CommunicationView]);
        $this->assignProfile($inboxManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageInboxes,
        ]);
        $this->assignProfile($flowManager, $tenant, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageFlows,
        ]);

        foreach ([$viewer, $inboxManager] as $denied) {
            $this->authenticate($denied);
            $this->postJson('/api/v1/communication/flows', ['name' => 'Fluxo negado'])->assertForbidden();
        }

        $this->authenticate($flowManager);
        $this->postJson('/api/v1/communication/flows', ['name' => 'Permitido'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paused');
    }

    public function test_cross_tenant_flow_is_not_found(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $foreignAdmin = User::factory()->forTenant($foreign, TenantRole::TenantAdmin)->create();

        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id,
            'name' => 'Alien',
            'status' => FlowStatus::Paused,
            'lock_version' => 1,
        ]);

        $this->authenticate($admin);
        $this->getJson('/api/v1/communication/flows/'.$flow->id)->assertNotFound();
        $this->putJson('/api/v1/communication/flows/'.$flow->id.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertNotFound();

        $this->authenticate($foreignAdmin);
        $this->getJson('/api/v1/communication/flows/'.$flow->id)->assertOk();
    }

    public function test_draft_version_conflict_and_validate_publish_binding_invariants(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $created = $this->postJson('/api/v1/communication/flows', ['name' => 'Robô'])->assertCreated()->json('data');
        $flowId = (int) $created['id'];

        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
            'unexpected' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('unexpected');

        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 99,
            'graph' => $this->validGraph(),
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');

        $draft = $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->json('data');

        $this->assertArrayHasKey('graph', $draft);
        $this->assertSame('start', $draft['graph']['nodes'][0]['type']);

        $this->postJson('/api/v1/communication/flows/'.$flowId.'/validate', [
            'graph' => [
                'nodes' => [
                    ['id' => 's', 'type' => 'start', 'data' => []],
                    ['id' => 'a', 'type' => 'message', 'data' => ['body' => 'A']],
                    ['id' => 'b', 'type' => 'message', 'data' => ['body' => 'B']],
                ],
                'edges' => [
                    ['source' => 's', 'target' => 'a'],
                    ['source' => 'a', 'target' => 'b'],
                    ['source' => 'b', 'target' => 'a'],
                ],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_flow_graph');

        $this->postJson('/api/v1/communication/flows/'.$flowId.'/validate')
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $published = $this->postJson('/api/v1/communication/flows/'.$flowId.'/publish', [
            'lock_version' => 2,
        ])->assertCreated()->json('data');

        $this->assertSame(0, (int) $published['bindings_enabled']);
        $versionId = (int) $published['version']['id'];
        $this->assertSame(1, (int) $published['version']['version']);

        $versionsBefore = CommunicationFlowVersion::query()->where('flow_id', $flowId)->count();
        $this->assertSame(1, $versionsBefore);

        // publish ≠ enable: flow stays paused, no enabled bindings
        $this->assertSame('paused', CommunicationFlow::query()->findOrFail($flowId)->status->value);
        $this->assertSame(0, CommunicationFlowInboxBinding::query()->where('enabled', true)->count());

        $inboxA = $this->inbox($tenant, 'A');
        $inboxB = $this->inbox($tenant, 'B');

        $bindingA = $this->postJson('/api/v1/communication/flows/'.$flowId.'/bindings', [
            'inbox_id' => $inboxA->id,
            'published_version_id' => $versionId,
        ])->assertCreated()
            ->assertJsonPath('data.enabled', false)
            ->json('data');

        $this->postJson('/api/v1/communication/flow-bindings/'.$bindingA['id'].'/enable', [
            'lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.enabled', true);

        $flow2 = $this->postJson('/api/v1/communication/flows', ['name' => 'Outro'])->assertCreated()->json('data');
        $this->putJson('/api/v1/communication/flows/'.$flow2['id'].'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();
        $pub2 = $this->postJson('/api/v1/communication/flows/'.$flow2['id'].'/publish', [
            'lock_version' => 2,
        ])->assertCreated()->json('data.version.id');

        $bindingB = $this->postJson('/api/v1/communication/flows/'.$flow2['id'].'/bindings', [
            'inbox_id' => $inboxA->id,
            'published_version_id' => $pub2,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/communication/flow-bindings/'.$bindingB['id'].'/enable', [
            'lock_version' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'enabled_binding_conflict');

        $bindingNoVersion = $this->postJson('/api/v1/communication/flows/'.$flowId.'/bindings', [
            'inbox_id' => $inboxB->id,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/communication/flow-bindings/'.$bindingNoVersion['id'].'/enable', [
            'lock_version' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 'published_version_required');
    }

    public function test_flag_off_blocks_publish_and_enable(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Flag'])->assertCreated()->json('data');
        $this->putJson('/api/v1/communication/flows/'.$flow['id'].'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();

        config(['communication.flows.enabled' => false]);

        $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/publish', [
            'lock_version' => 2,
        ])->assertForbidden()->assertJsonPath('code', 'communication_flows_disabled');

        $this->postJson('/api/v1/communication/flows', ['name' => 'Novo'])
            ->assertForbidden()
            ->assertJsonPath('code', 'communication_flows_disabled');
    }

    public function test_clone_from_published_version_creates_paused_draft(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Origem'])->assertCreated()->json('data');
        $this->putJson('/api/v1/communication/flows/'.$flow['id'].'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();
        $versionId = (int) $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/publish', [
            'lock_version' => 2,
        ])->assertCreated()->json('data.version.id');

        $clone = $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/clone', [
            'name' => 'Cópia',
            'from_version_id' => $versionId,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Cópia')
            ->assertJsonPath('data.status', 'paused')
            ->json('data');

        $draft = $this->getJson('/api/v1/communication/flows/'.$clone['id'].'/draft')
            ->assertOk()
            ->json('data');
        $this->assertSame('message', $draft['graph']['nodes'][1]['type']);
        $this->assertSame(1, CommunicationFlowVersion::query()->where('flow_id', $flow['id'])->count());
        $this->assertSame(0, CommunicationFlowVersion::query()->where('flow_id', $clone['id'])->count());
    }

    public function test_catalog_detail_update_clone_version_and_delete_preserve_contracts(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);

        $created = $this->postJson('/api/v1/communication/flows', [
            'name' => 'Fluxo contratual',
        ])->assertCreated()->json('data');
        $flowId = (int) $created['id'];

        $this->getJson('/api/v1/communication/flows')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.flows_enabled', true)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'name',
                    'status',
                    'lock_version',
                    'created_at',
                    'updated_at',
                ]],
                'meta' => ['flows_enabled'],
            ]);

        $this->getJson('/api/v1/communication/flows/'.$flowId)
            ->assertOk()
            ->assertJsonPath('data.id', $flowId)
            ->assertJsonStructure([
                'data' => [
                    'draft',
                    'versions',
                    'bindings',
                ],
            ]);

        $this->patchJson('/api/v1/communication/flows/'.$flowId, [
            'name' => 'Fluxo renomeado',
            'status' => FlowStatus::Active->value,
            'lock_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Fluxo renomeado')
            ->assertJsonPath('data.status', FlowStatus::Active->value)
            ->assertJsonPath('data.lock_version', 2);

        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();
        $versionId = (int) $this->postJson('/api/v1/communication/flows/'.$flowId.'/publish', [
            'lock_version' => 2,
        ])->assertCreated()->json('data.version.id');

        $clone = $this->postJson(
            '/api/v1/communication/flows/'.$flowId.'/versions/'.$versionId.'/clone',
        )->assertCreated()
            ->assertJsonPath('data.name', 'Fluxo renomeado (cópia)')
            ->assertJsonPath('data.status', FlowStatus::Paused->value)
            ->json('data');
        $cloneId = (int) $clone['id'];
        $this->getJson('/api/v1/communication/flows/'.$cloneId.'/draft')
            ->assertOk()
            ->assertJsonPath('data.graph.nodes.1.type', 'message');

        $this->deleteJson('/api/v1/communication/flows/'.$cloneId)
            ->assertNoContent();
        $this->assertDatabaseMissing('communication_flows', ['id' => $cloneId]);
        $this->assertDatabaseHas('communication_events', [
            'tenant_id' => $tenant->id,
            'type' => 'COMMUNICATION_FLOW_DELETED',
        ]);
    }

    public function test_flow_boundaries_reject_tenant_selector_input(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);
        [$flowId, $versionId] = $this->publishedFlow('Boundary');
        $inbox = $this->inbox($tenant, 'Boundary');
        $binding = $this->postJson('/api/v1/communication/flows/'.$flowId.'/bindings', [
            'inbox_id' => $inbox->id,
            'published_version_id' => $versionId,
        ])->assertCreated()->json('data');
        $bindingId = (int) $binding['id'];

        $responses = [
            $this->getJson('/api/v1/communication/flows?tenant_id='.$tenant->id),
            $this->getJson('/api/v1/communication/flows/'.$flowId.'?tenant_id='.$tenant->id),
            $this->postJson('/api/v1/communication/flows', [
                'name' => 'Override',
                'tenant_id' => $tenant->id,
            ]),
            $this->patchJson('/api/v1/communication/flows/'.$flowId, [
                'lock_version' => 1,
                'tenant_id' => $tenant->id,
            ]),
            $this->deleteJson('/api/v1/communication/flows/'.$flowId, [
                'tenant_id' => $tenant->id,
            ]),
            $this->getJson('/api/v1/communication/flows/'.$flowId.'/draft?tenant_id='.$tenant->id),
            $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
                'lock_version' => 2,
                'graph' => $this->validGraph(),
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/validate', [
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/dry-run', [
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/preview', [
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/publish', [
                'lock_version' => 2,
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/clone', [
                'name' => 'Override clone',
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson(
                '/api/v1/communication/flows/'.$flowId.'/versions/'.$versionId.'/clone',
                ['tenant_id' => $tenant->id],
            ),
            $this->getJson('/api/v1/communication/flows/'.$flowId.'/bindings?tenant_id='.$tenant->id),
            $this->postJson('/api/v1/communication/flows/'.$flowId.'/bindings', [
                'inbox_id' => $inbox->id,
                'tenant_id' => $tenant->id,
            ]),
            $this->patchJson('/api/v1/communication/flow-bindings/'.$bindingId, [
                'lock_version' => 1,
                'tenant_id' => $tenant->id,
            ]),
            $this->postJson('/api/v1/communication/flow-bindings/'.$bindingId.'/enable', [
                'lock_version' => 1,
                'tenant_id' => $tenant->id,
            ]),
            $this->deleteJson('/api/v1/communication/flow-bindings/'.$bindingId, [
                'tenant_id' => $tenant->id,
            ]),
        ];

        foreach ($responses as $response) {
            $response->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }
        $this->assertDatabaseHas('communication_flows', ['id' => $flowId]);
        $this->assertDatabaseHas('communication_flow_inbox_bindings', ['id' => $bindingId]);
    }

    public function test_binding_updates_are_atomic_versioned_and_tenant_scoped(): void
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $foreign = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $this->authenticate($admin);
        [$flowId, $versionId] = $this->publishedFlow('Principal');
        [$otherFlowId, $otherVersionId] = $this->publishedFlow('Outro');
        $inbox = $this->inbox($tenant, 'Binding');
        $binding = $this->postJson('/api/v1/communication/flows/'.$flowId.'/bindings', [
            'inbox_id' => $inbox->id,
            'published_version_id' => $versionId,
            'enabled' => true,
        ])->assertCreated()->json('data');
        $bindingId = (int) $binding['id'];
        $eventsBefore = CommunicationEvent::query()
            ->withoutGlobalScopes()
            ->where('type', 'COMMUNICATION_FLOW_BINDING_UPDATED')
            ->count();

        $this->patchJson('/api/v1/communication/flow-bindings/'.$bindingId, [
            'lock_version' => 1,
            'published_version_id' => $otherVersionId,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_published_version');
        $this->assertDatabaseHas('communication_flow_inbox_bindings', [
            'id' => $bindingId,
            'flow_id' => $flowId,
            'published_version_id' => $versionId,
            'enabled' => true,
            'lock_version' => 1,
        ]);
        $this->assertSame(
            $eventsBefore,
            CommunicationEvent::query()->withoutGlobalScopes()
                ->where('type', 'COMMUNICATION_FLOW_BINDING_UPDATED')
                ->count(),
        );

        $this->patchJson('/api/v1/communication/flow-bindings/'.$bindingId, [
            'lock_version' => 1,
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.lock_version', 2);

        $eventsAfterUpdate = CommunicationEvent::query()
            ->withoutGlobalScopes()
            ->where('type', 'COMMUNICATION_FLOW_BINDING_UPDATED')
            ->count();
        $this->postJson('/api/v1/communication/flow-bindings/'.$bindingId.'/enable', [
            'lock_version' => 1,
        ])->assertConflict()
            ->assertJsonPath('code', 'version_conflict');
        $this->assertDatabaseHas('communication_flow_inbox_bindings', [
            'id' => $bindingId,
            'enabled' => false,
            'lock_version' => 2,
        ]);
        $this->assertSame(
            $eventsAfterUpdate,
            CommunicationEvent::query()->withoutGlobalScopes()
                ->where('type', 'COMMUNICATION_FLOW_BINDING_UPDATED')
                ->count(),
        );

        $this->postJson('/api/v1/communication/flow-bindings/'.$bindingId.'/enable', [
            'lock_version' => 2,
        ])->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.lock_version', 3);
        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flowId,
            'flow_version_id' => $versionId,
            'binding_id' => $bindingId,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 's',
            'context_encrypted' => [],
            'started_at' => now(),
        ]);
        $this->deleteJson('/api/v1/communication/flow-bindings/'.$bindingId)
            ->assertNoContent();
        $this->assertDatabaseMissing('communication_flow_inbox_bindings', ['id' => $bindingId]);
        $this->assertSame(FlowRunStatus::Stopped, $run->refresh()->status);
        $this->assertNull($run->binding_id);
        $this->assertNotNull($run->finished_at);

        $foreignFlow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id,
            'name' => 'Foreign binding',
            'status' => FlowStatus::Paused,
            'lock_version' => 1,
        ]);
        $foreignInbox = $this->inbox($foreign, 'Foreign');
        $foreignBinding = CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->create([
            'tenant_id' => $foreign->id,
            'flow_id' => $foreignFlow->id,
            'inbox_id' => $foreignInbox->id,
            'enabled' => false,
            'lock_version' => 1,
        ]);
        $this->patchJson('/api/v1/communication/flow-bindings/'.$foreignBinding->id, [
            'lock_version' => 1,
            'enabled' => true,
        ])->assertNotFound();
        $this->deleteJson('/api/v1/communication/flow-bindings/'.$foreignBinding->id)
            ->assertNotFound();
        $this->assertDatabaseHas('communication_flow_inbox_bindings', [
            'id' => $foreignBinding->id,
            'flow_id' => $foreignFlow->id,
        ]);
        $this->assertNotSame($flowId, $otherFlowId);
    }

    public function test_flow_executor_jobs_are_registered(): void
    {
        $this->assertTrue(class_exists(CorrelateFlowEventJob::class));
        $this->assertTrue(class_exists(AdvanceFlowRunJob::class));
        $this->assertTrue(class_exists(CommunicationFlowRun::class));
    }

    /** @param list<TenantPermission> $permissions */
    private function assignProfile(User $user, Tenant $tenant, array $permissions): void
    {
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys($permissions);
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $membership->forceFill([
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => (int) $membership->authorization_version + 1,
        ])->save();
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function inbox(Tenant $tenant, string $suffix): CommunicationInbox
    {
        return CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox '.$suffix,
            'session_id' => 'session-'.Str::ulid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'lock_version' => 1,
        ]);
    }

    /** @return array{int, int} */
    private function publishedFlow(string $name): array
    {
        $flowId = (int) $this->postJson('/api/v1/communication/flows', [
            'name' => $name,
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();
        $versionId = (int) $this->postJson('/api/v1/communication/flows/'.$flowId.'/publish', [
            'lock_version' => 2,
        ])->assertCreated()->json('data.version.id');

        return [$flowId, $versionId];
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    private function validGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Olá']],
                ['id' => 'h', 'type' => 'handoff', 'data' => []],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm'],
                ['id' => 'e2', 'source' => 'm', 'target' => 'h'],
                ['id' => 'e3', 'source' => 'h', 'target' => 'e'],
            ],
        ];
    }
}
