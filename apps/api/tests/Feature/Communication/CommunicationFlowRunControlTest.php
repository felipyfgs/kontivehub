<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageKind;
use App\Enums\CommunicationChannel;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Exceptions\CommunicationTransportException;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationFlowRunControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(CommunicationTransport::class, new class implements CommunicationTransport
        {
            public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
            {
                return new GatewayCommandReceipt($command->commandId, false);
            }

            public function query(GatewayQueryData $query): array
            {
                return [
                    'query_id' => $query->queryId,
                    'type' => $query->type->value,
                    'result' => [],
                ];
            }

            public function sessionStatus(string $sessionId): array
            {
                return [
                    'session_id' => $sessionId,
                    'status' => 'CONNECTED',
                    'desired_connected' => true,
                    'reconnect_count' => 0,
                    'connected' => true,
                    'logged_in' => true,
                    'ready' => true,
                    'has_credentials' => true,
                ];
            }

            public function downloadMedia(string $spoolId): StreamInterface
            {
                throw new CommunicationTransportException('MEDIA_NOT_CONFIGURED', false);
            }
        });
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.flows.enabled' => true,
            'communication.flows.runtime_enabled' => true,
        ]);
    }

    public function test_list_and_show_runs_for_tenant(): void
    {
        [$tenant, $run, $admin] = $this->seedRun();
        Sanctum::actingAs($admin);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/flow-runs?flow_id='.$run->flow_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('data.0.status', 'running');

        $this->getJson('/api/v1/communication/flow-runs?flow_id='.$run->flow_id.'&active_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/communication/flow-runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.id', $run->id)
            ->assertJsonPath('data.conversation_id', $run->conversation_id);

        $run->forceFill(['status' => FlowRunStatus::Stopped, 'finished_at' => now()])->save();

        $this->getJson('/api/v1/communication/flow-runs?flow_id='.$run->flow_id.'&active_only=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/communication/flow-runs?flow_id='.$run->flow_id.'&status=stopped')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'stopped');
    }

    public function test_list_runs_hides_cross_tenant(): void
    {
        [, $run] = $this->seedRun();
        $other = Tenant::factory()->create(['communication_enabled' => true]);
        $adminOther = User::factory()->forTenant($other, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($adminOther);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/communication/flow-runs?flow_id='.$run->flow_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/communication/flow-runs/'.$run->id)->assertNotFound();
    }

    public function test_pause_resume_stop_restart_and_permission_denial(): void
    {
        [$tenant, $run, $admin] = $this->seedRun();
        Sanctum::actingAs($admin);
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/communication/flow-runs/'.$run->id.'/pause')
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');
        $this->assertSame(FlowRunStatus::Paused, $run->refresh()->status);

        $this->postJson('/api/v1/communication/flow-runs/'.$run->id.'/resume')
            ->assertOk()
            ->assertJsonPath('data.status', 'running');

        $this->postJson('/api/v1/communication/flow-runs/'.$run->id.'/stop')
            ->assertOk()
            ->assertJsonPath('data.status', 'stopped');
        $this->assertNotNull($run->refresh()->finished_at);

        // restart from stopped
        $response = $this->postJson('/api/v1/communication/flow-runs/'.$run->id.'/restart')
            ->assertOk();
        $newId = (int) $response->json('data.id');
        $this->assertNotSame((int) $run->id, $newId);
        $this->assertSame(FlowRunStatus::Pending, CommunicationFlowRun::query()->withoutGlobalScopes()->findOrFail($newId)->status);

        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::CommunicationView]);
        $membership = TenantMembership::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('user_id', $viewer->id)->firstOrFail();
        $membership->forceFill([
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'authorization_version' => (int) $membership->authorization_version + 1,
        ])->save();
        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();
        $this->postJson('/api/v1/communication/flow-runs/'.$newId.'/pause')->assertForbidden();
    }

    public function test_cross_tenant_run_is_hidden(): void
    {
        [, $run] = $this->seedRun();
        $other = Tenant::factory()->create(['communication_enabled' => true]);
        $adminOther = User::factory()->forTenant($other, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($adminOther);
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/communication/flow-runs/'.$run->id.'/pause')->assertNotFound();
    }

    public function test_human_outbound_handoffs_active_run(): void
    {
        [$tenant, $run, $admin, $conversation] = $this->seedRun();
        Sanctum::actingAs($admin);
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/communication/conversations/'.$conversation->id.'/messages', [
            'body' => 'Humano no comando',
            'kind' => MessageKind::Text->value,
            'idempotency_key' => 'human-key-0001',
        ])->assertStatus(202);

        $this->assertSame(FlowRunStatus::HandedOff, $run->refresh()->status);
    }

    public function test_purge_terminates_active_runs(): void
    {
        [$tenant, $run, $admin, $conversation] = $this->seedRun();
        Sanctum::actingAs($admin);
        app(CurrentTenant::class)->clear();
        $contactId = (int) $conversation->identity->contact_id;

        $this->deleteJson('/api/v1/communication/contacts/'.$contactId.'/personal-data')
            ->assertOk();

        $this->assertSame(FlowRunStatus::Purged, $run->refresh()->status);
    }

    /** @return array{0:Tenant,1:CommunicationFlowRun,2:User,3:CommunicationConversation} */
    private function seedRun(): array
    {
        $tenant = Tenant::factory()->create(['communication_enabled' => true]);
        $admin = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inbox',
            'session_id' => 'session-ctrl-'.uniqid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'lock_version' => 1,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Cliente',
            'is_active' => true,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => '+5511977770001',
            'address_hash' => hash('sha256', '+5511977770001'),
            'address_masked' => '****0001',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => 'OPEN',
            'lock_version' => 1,
        ]);
        $conversation->setRelation('identity', $identity);
        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Fluxo',
            'status' => FlowStatus::Active,
            'lock_version' => 1,
        ]);
        $graph = [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [['id' => 'e1', 'source' => 's', 'target' => 'e']],
        ];
        $version = CommunicationFlowVersion::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flow->id,
            'version' => 1,
            'graph_encrypted' => $graph,
            'graph_digest' => hash('sha256', 'g'),
            'published_at' => now(),
        ]);
        $binding = CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flow->id,
            'inbox_id' => $inbox->id,
            'published_version_id' => $version->id,
            'enabled' => true,
            'lock_version' => 1,
        ]);
        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 's',
            'context_encrypted' => [],
            'started_at' => now(),
        ]);

        return [$tenant, $run, $admin, $conversation];
    }
}
