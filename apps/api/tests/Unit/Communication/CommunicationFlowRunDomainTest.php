<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\FlowRunStatus;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowConsumption;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\Office;
use App\Services\Communication\Flows\CommunicationFlowConsumptionService;
use App\Services\Communication\Flows\CommunicationFlowLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CommunicationFlowRunDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumption_is_idempotent(): void
    {
        $office = Office::factory()->create();
        $service = app(CommunicationFlowConsumptionService::class);

        $this->assertTrue($service->consumeOnce((int) $office->id, 'evt-1', eventDigest: hash('sha256', 'a')));
        $this->assertFalse($service->consumeOnce((int) $office->id, 'evt-1', eventDigest: hash('sha256', 'a')));
        $this->assertSame(1, CommunicationFlowConsumption::query()->withoutGlobalScopes()->count());
    }

    public function test_only_one_active_run_per_conversation(): void
    {
        [$office, $conversation, $version, $binding] = $this->seedConversation();

        CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 's',
            'context_encrypted' => ['x' => 'secret-value'],
            'started_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Pending,
            'current_node_id' => 's',
            'started_at' => now(),
        ]);
    }

    public function test_context_is_encrypted_at_rest(): void
    {
        [$office, $conversation, $version, $binding] = $this->seedConversation();
        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::WaitingInput,
            'current_node_id' => 'q',
            'context_encrypted' => ['answers' => ['q' => ['option' => 'sim']]],
            'started_at' => now(),
        ]);

        $raw = DB::table('communication_flow_runs')->where('id', $run->id)->value('context_encrypted');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('sim', $raw);
        $this->assertStringNotContainsString('answers', $raw);

        $fresh = CommunicationFlowRun::query()->withoutGlobalScopes()->findOrFail($run->id);
        $this->assertSame('sim', $fresh->context_encrypted['answers']['q']['option']);
    }

    public function test_ordered_lock_helper_loads_conversation_then_run(): void
    {
        [$office, $conversation, $version, $binding] = $this->seedConversation();
        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $version->flow_id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 's',
            'started_at' => now(),
        ]);

        $seen = app(CommunicationFlowLock::class)->withConversationAndRun(
            (int) $conversation->id,
            (int) $run->id,
            static fn ($c, $r): array => [(int) $c->id, (int) $r->id],
        );
        $this->assertSame([(int) $conversation->id, (int) $run->id], $seen);
    }

    /** @return array{0:Office,1:CommunicationConversation,2:CommunicationFlowVersion,3:CommunicationFlowInboxBinding} */
    private function seedConversation(): array
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Inbox',
            'session_id' => 'session-'.uniqid(),
            'status' => 'CONNECTED',
            'is_enabled' => true,
            'lock_version' => 1,
        ]);
        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Fluxo',
            'status' => 'active',
            'lock_version' => 1,
        ]);
        $version = CommunicationFlowVersion::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $flow->id,
            'version' => 1,
            'graph_encrypted' => [
                'nodes' => [['id' => 's', 'type' => 'start', 'data' => []]],
                'edges' => [],
            ],
            'graph_digest' => hash('sha256', 'g'),
            'published_at' => now(),
        ]);
        $binding = CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $flow->id,
            'inbox_id' => $inbox->id,
            'published_version_id' => $version->id,
            'enabled' => true,
            'lock_version' => 1,
        ]);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'contact_id' => CommunicationContact::query()->withoutGlobalScopes()->create([
                'office_id' => $office->id,
                'is_provisional' => true,
                'is_active' => true,
            ])->id,
            'channel' => 'WHATSAPP',
            'address_encrypted' => '5511999999999',
            'address_hash' => hash('sha256', '5511999999999'),
            'address_masked' => '****9999',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => 'OPEN',
            'lock_version' => 1,
        ]);

        return [$office, $conversation, $version, $binding];
    }
}
