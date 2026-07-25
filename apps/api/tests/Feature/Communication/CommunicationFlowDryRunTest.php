<?php

namespace Tests\Feature\Communication;

use App\Enums\Communication\FlowStatus;
use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Events\CommunicationEventCommitted;
use App\Jobs\Communication\AdvanceCommunicationFlowRunJob;
use App\Jobs\Communication\CorrelateCommunicationFlowEventJob;
use App\Models\CommunicationEvent;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowDraft;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationOutboxEntry;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CommunicationFlowDryRunTest extends TestCase
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
            'communication.flows.runtime_enabled' => true,
        ]);
    }

    public function test_dry_run_simulates_without_outbox_jobs_or_runs(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $this->authenticate($admin);

        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Dry'])
            ->assertCreated()
            ->json('data');
        $flowId = (int) $flow['id'];

        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();

        $outboxBefore = CommunicationOutboxEntry::query()->withoutGlobalScopes()->count();
        $runsBefore = CommunicationFlowRun::query()->withoutGlobalScopes()->count();

        $response = $this->postJson('/api/v1/communication/flows/'.$flowId.'/dry-run')
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.outcome', 'handed_off')
            ->assertJsonPath('data.side_effects.outbox_created', false)
            ->assertJsonPath('data.side_effects.flow_run_persisted', false)
            ->assertJsonPath('data.side_effects.correlation_jobs_dispatched', false)
            ->assertJsonPath('data.side_effects.gateway_called', false);

        $steps = $response->json('data.steps');
        $this->assertIsArray($steps);
        $this->assertGreaterThanOrEqual(3, count($steps));
        $this->assertSame('start', $steps[0]['node_type']);
        $this->assertSame('simulated_send', $steps[1]['status']);
        $this->assertFalse($steps[1]['detail']['egress']);
        $this->assertArrayHasKey('body_preview', $steps[1]['detail']);
        $this->assertArrayNotHasKey('body', $steps[1]['detail']);
        $this->assertNotSame('Olá', $steps[1]['detail']['body_preview']);
        $this->assertStringContainsString('•', $steps[1]['detail']['body_preview']);
        $this->assertSame(hash('sha256', 'Olá'), $steps[1]['detail']['body_digest']);

        $this->assertSame($outboxBefore, CommunicationOutboxEntry::query()->withoutGlobalScopes()->count());
        $this->assertSame($runsBefore, CommunicationFlowRun::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
        Queue::assertNotPushed(AdvanceCommunicationFlowRunJob::class);
        Queue::assertNotPushed(CorrelateCommunicationFlowEventJob::class);
    }

    public function test_dry_run_accepts_graph_body_and_flag_off_denies(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $this->authenticate($admin);

        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Body'])
            ->assertCreated()
            ->json('data');
        $flowId = (int) $flow['id'];

        $this->postJson('/api/v1/communication/flows/'.$flowId.'/dry-run', [
            'graph' => $this->validGraph(['body' => 'Mensagem do body']),
        ])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.outcome', 'handed_off');

        config(['communication.flows.enabled' => false]);

        $this->postJson('/api/v1/communication/flows/'.$flowId.'/dry-run')
            ->assertForbidden()
            ->assertJsonPath('code', 'communication_flows_disabled');

        $this->postJson('/api/v1/communication/flows/'.$flowId.'/preview')
            ->assertForbidden()
            ->assertJsonPath('code', 'communication_flows_disabled');
    }

    public function test_dry_run_and_preview_are_cross_office_denied(): void
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $foreign = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();

        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'office_id' => $foreign->id,
            'name' => 'Alien',
            'status' => FlowStatus::Paused,
            'lock_version' => 1,
        ]);
        CommunicationFlowDraft::query()->withoutGlobalScopes()->create([
            'office_id' => $foreign->id,
            'flow_id' => $flow->id,
            'graph_encrypted' => $this->validGraph(),
            'graph_digest' => hash('sha256', 'x'),
            'lock_version' => 1,
        ]);

        $this->authenticate($admin);
        $this->postJson('/api/v1/communication/flows/'.$flow->id.'/dry-run')->assertNotFound();
        $this->postJson('/api/v1/communication/flows/'.$flow->id.'/preview')->assertNotFound();
    }

    public function test_preview_masks_pii_and_audit_omits_plaintext(): void
    {
        Event::fake();

        $office = Office::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $this->authenticate($admin);

        $sensitive = 'Olá cliente CPF 123.456.789-00 email joao@empresa.com.br tel +5511987654321';
        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Preview'])
            ->assertCreated()
            ->json('data');
        $flowId = (int) $flow['id'];

        $this->putJson('/api/v1/communication/flows/'.$flowId.'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(['body' => $sensitive]),
        ])->assertOk();

        $preview = $this->postJson('/api/v1/communication/flows/'.$flowId.'/preview')
            ->assertOk()
            ->json('data');

        $body = $preview['graph']['nodes'][1]['data']['body'] ?? '';
        $this->assertIsString($body);
        $this->assertStringNotContainsString('123.456.789-00', $body);
        $this->assertStringNotContainsString('joao@empresa.com.br', $body);
        $this->assertStringNotContainsString('987654321', $body);
        $this->assertStringContainsString('***.***.***-**', $body);
        $this->assertStringContainsString('[email-mascarado]', $body);
        $this->assertNotEmpty($preview['masked_paths']);

        $dry = $this->postJson('/api/v1/communication/flows/'.$flowId.'/dry-run')
            ->assertOk()
            ->json('data');

        $encoded = json_encode($dry) ?: '';
        $this->assertStringNotContainsString('123.456.789-00', $encoded);
        $this->assertStringNotContainsString('joao@empresa.com.br', $encoded);

        $event = CommunicationEvent::query()
            ->withoutGlobalScopes()
            ->where('type', 'COMMUNICATION_FLOW_DRY_RUN')
            ->where('office_id', $office->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $payload = json_encode($event->payload ?? []) ?: '';
        $this->assertStringNotContainsString('123.456.789-00', $payload);
        $this->assertStringNotContainsString($sensitive, $payload);
        $this->assertArrayHasKey('graph_digest', $event->payload);
    }

    public function test_preview_requires_manage_flows(): void
    {
        config(['features.canonical_multitenant_rbac.enabled' => true]);
        $office = Office::factory()->create(['communication_enabled' => true]);
        $viewer = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $manager = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $this->assignProfile($viewer, $office, [TenantPermission::CommunicationView]);
        $this->assignProfile($manager, $office, [
            TenantPermission::CommunicationView,
            TenantPermission::CommunicationManageFlows,
        ]);

        $this->authenticate($manager);
        $flow = $this->postJson('/api/v1/communication/flows', ['name' => 'Perm'])
            ->assertCreated()
            ->json('data');
        $this->putJson('/api/v1/communication/flows/'.$flow['id'].'/draft', [
            'lock_version' => 1,
            'graph' => $this->validGraph(),
        ])->assertOk();

        $this->authenticate($viewer);
        $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/preview')->assertForbidden();
        $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/dry-run')->assertForbidden();

        $this->authenticate($manager);
        $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/preview')->assertOk();
        $this->postJson('/api/v1/communication/flows/'.$flow['id'].'/dry-run')->assertOk();
    }

    /** @param list<TenantPermission> $permissions */
    private function assignProfile(User $user, Office $office, array $permissions): void
    {
        $profile = TenantPermissionProfile::factory()->forOffice($office)->create();
        $profile->syncPermissionKeys($permissions);
        $membership = OfficeMembership::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
        $membership->forceFill([
            'tenant_role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => (int) $membership->authorization_version + 1,
        ])->save();
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
    }

    /**
     * @param  array{body?: string}  $overrides
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function validGraph(array $overrides = []): array
    {
        $body = $overrides['body'] ?? 'Olá';

        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => $body]],
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
