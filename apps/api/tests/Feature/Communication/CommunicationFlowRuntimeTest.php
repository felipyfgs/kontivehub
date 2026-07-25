<?php

namespace Tests\Feature\Communication;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\OutboxStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationTransportException;
use App\Jobs\Communication\AdvanceCommunicationFlowRunJob;
use App\Jobs\Communication\CorrelateCommunicationFlowEventJob;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowConsumption;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowRunStep;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Office;
use App\Services\Communication\Flows\CommunicationFlowCorrelator;
use App\Services\Communication\Flows\CommunicationFlowExecutor;
use App\Services\Communication\Security\CommunicationHmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

final class CommunicationFlowRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private FlowRuntimeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FlowRuntimeTransport;
        $this->app->instance(CommunicationTransport::class, $this->transport);
        config([
            'communication.enabled' => true,
            'communication.gateway.enabled' => true,
            'communication.flows.enabled' => true,
            'communication.flows.runtime_enabled' => true,
            'communication.hmac.current_key_id' => 'test-key',
            'communication.hmac.current_secret' => str_repeat('h', 32),
            'queue.default' => 'sync',
        ]);
    }

    public function test_history_does_not_start_run(): void
    {
        [$inbox] = $this->seedFlow(active: true, enabled: true);

        $this->postEvent($inbox, GatewayEventType::HistorySynced, 'hist-batch-0001', [
            'messages' => [[
                'provider_message_id' => 'hist-msg-1',
                'from' => '+5511988880001',
                'text' => 'histórico',
                'direction' => 'INBOUND',
                'kind' => 'TEXT',
            ]],
            'complete' => true,
        ])->assertNoContent();

        $this->assertSame(0, CommunicationFlowRun::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, CommunicationFlowConsumption::query()->withoutGlobalScopes()->count());
    }

    public function test_flag_off_is_noop(): void
    {
        config(['communication.flows.runtime_enabled' => false]);
        [$inbox] = $this->seedFlow(active: true, enabled: true);

        Queue::fake();
        $this->postLiveInbound($inbox, 'gw-flag-off-0001', 'provider-flag-off-0001', 'oi');
        Queue::assertNotPushed(CorrelateCommunicationFlowEventJob::class);
        // Mesmo se correlacionar manualmente, no-op:
        app(CommunicationFlowCorrelator::class)->correlateMessage(
            (int) $inbox->office_id,
            (int) CommunicationConversation::query()->withoutGlobalScopes()->value('id'),
            (int) CommunicationMessage::query()->withoutGlobalScopes()->value('id'),
            'gw:gw-off-1',
        );
        $this->assertSame(0, CommunicationFlowRun::query()->withoutGlobalScopes()->count());
    }

    public function test_live_inbound_starts_run_and_queues_message_once(): void
    {
        [$inbox, $conversation] = $this->seedFlow(active: true, enabled: true, graph: $this->messageGraph());

        $this->postLiveInbound($inbox, 'gw-live-inbound-0001', 'provider-live-inbound-0001', 'olá');

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->first();
        $this->assertNotNull($run);
        $this->assertSame((int) $conversation->id, (int) $run->conversation_id);
        $this->assertContains($run->status, [
            FlowRunStatus::WaitingOutbox,
            FlowRunStatus::Running,
            FlowRunStatus::Completed,
            FlowRunStatus::WaitingDelay,
        ]);

        $outbox = CommunicationOutboxEntry::query()->withoutGlobalScopes()->whereNotNull('effect_key')->get();
        $this->assertCount(1, $outbox);
        $this->assertSame(OutboxStatus::Accepted, $outbox->first()->status);

        $flowMessages = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count();
        $this->assertSame(1, $flowMessages);
    }

    public function test_event_redelivery_is_idempotent(): void
    {
        [$inbox] = $this->seedFlow(active: true, enabled: true, graph: $this->messageGraph());
        $this->postLiveInbound($inbox, 'gw-idempotent-0001', 'provider-idempotent-0001', 'ping');

        $runCount = CommunicationFlowRun::query()->withoutGlobalScopes()->count();
        $outboxCount = CommunicationOutboxEntry::query()->withoutGlobalScopes()->whereNotNull('effect_key')->count();

        app(CommunicationFlowCorrelator::class)->correlateMessage(
            (int) $inbox->office_id,
            (int) CommunicationConversation::query()->withoutGlobalScopes()->value('id'),
            (int) CommunicationMessage::query()->withoutGlobalScopes()
                ->where('provider_message_id', 'provider-idempotent-0001')->value('id'),
            'gw:gw-idempotent-0001',
        );

        $this->assertSame($runCount, CommunicationFlowRun::query()->withoutGlobalScopes()->count());
        $this->assertSame($outboxCount, CommunicationOutboxEntry::query()->withoutGlobalScopes()->whereNotNull('effect_key')->count());
    }

    public function test_flow_outbound_does_not_retrigger_runtime(): void
    {
        [$inbox, $conversation] = $this->seedFlow(active: true, enabled: true, graph: $this->messageGraph());
        $this->postLiveInbound($inbox, 'gw-runtime-0001', 'provider-runtime-0001', 'start');

        $before = CommunicationFlowRun::query()->withoutGlobalScopes()->count();
        $flowMsg = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->firstOrFail();

        // Eco outbound do hub não deve correlacionar avanço
        Queue::fake();
        $this->postEvent($inbox, GatewayEventType::MessageReceived, 'gw-echo-outbound-0001', [
            'provider_message_id' => 'echo-'.$flowMsg->provider_message_id,
            'from' => '+5511988880001',
            'text' => 'bot',
            'direction' => 'OUTBOUND',
            'kind' => 'TEXT',
        ])->assertNoContent();
        Queue::assertNotPushed(CorrelateCommunicationFlowEventJob::class);
        $this->assertSame($before, CommunicationFlowRun::query()->withoutGlobalScopes()->count());

        // Mensagem FLOW_AUTOMATION inbound (defesa em profundidade)
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Inbound,
            'kind' => 'TEXT',
            'source' => MessageSource::FlowAutomation,
            'status' => 'DELIVERED',
            'body_encrypted' => 'x',
            'provider_message_id' => 'flow-inbound-ignore',
            'content_digest' => hash('sha256', 'x'),
            'metadata' => ['source' => 'flow_runtime'],
            'occurred_at' => now(),
        ]);
        app(CommunicationFlowCorrelator::class)->correlateMessage(
            (int) $inbox->office_id,
            (int) $conversation->id,
            (int) $message->id,
            'gw:should-ignore',
        );
        $this->assertSame(0, CommunicationFlowConsumption::query()->withoutGlobalScopes()
            ->where('event_key', 'gw:should-ignore')->count());
    }

    public function test_advance_only_after_outbox_accepted(): void
    {
        [$inbox] = $this->seedFlow(active: true, enabled: true, graph: $this->messageThenEndGraph());
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->firstOrFail();

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'flow_id' => CommunicationFlow::query()->withoutGlobalScopes()->value('id'),
            'flow_version_id' => CommunicationFlowVersion::query()->withoutGlobalScopes()->value('id'),
            'binding_id' => CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->value('id'),
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::WaitingOutbox,
            'current_node_id' => 'm',
            'context_encrypted' => [],
            'started_at' => now(),
            'waiting_effect_key' => 'flow:wait:m:message',
        ]);

        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Outbound,
            'kind' => 'TEXT',
            'source' => MessageSource::FlowAutomation,
            'status' => 'QUEUED',
            'body_encrypted' => 'Olá automático',
            'provider_message_id' => 'flow-wait-1',
            'content_digest' => hash('sha256', 'x'),
            'metadata' => ['source' => 'flow_runtime', 'effect_key' => 'flow:wait:m:message'],
            'occurred_at' => now(),
        ]);

        $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'inbox_id' => $inbox->id,
            'message_id' => $message->id,
            'command_id' => 'flow:wait:m:message',
            'effect_key' => 'flow:wait:m:message',
            'session_id' => $inbox->session_id,
            'type' => GatewayCommandType::SendMessage,
            'payload_encrypted' => ['to' => '+5511988880001', 'kind' => 'TEXT', 'text' => 'Olá automático'],
            'payload_digest' => hash('sha256', 'p'),
            'status' => OutboxStatus::Pending,
            'available_at' => now(),
        ]);
        $run->forceFill(['waiting_outbox_entry_id' => $entry->id])->save();

        CommunicationFlowRunStep::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'run_id' => $run->id,
            'node_id' => 'm',
            'node_type' => 'message',
            'seq' => 1,
            'status' => 'waiting_outbox',
            'effect_key' => 'flow:wait:m:message',
            'entered_at' => now(),
            'result_meta' => ['outbox_entry_id' => $entry->id],
        ]);

        app(CommunicationFlowExecutor::class)->advance((int) $run->id);
        $this->assertSame(FlowRunStatus::WaitingOutbox, $run->refresh()->status);
        $this->assertSame('m', $run->current_node_id);

        $entry->forceFill([
            'status' => OutboxStatus::Accepted,
            'accepted_at' => now(),
        ])->save();
        app(CommunicationFlowExecutor::class)->onOutboxAccepted($entry->fresh());
        // onOutboxAccepted dispara job sync
        $run->refresh();
        if ($run->status !== FlowRunStatus::Completed) {
            app(CommunicationFlowExecutor::class)->advance((int) $run->id);
        }
        $this->assertSame(FlowRunStatus::Completed, $run->refresh()->status);
    }

    public function test_delay_resume_is_idempotent(): void
    {
        [$inbox] = $this->seedFlow(active: true, enabled: true, graph: $this->delayGraph());
        $this->postLiveInbound($inbox, 'gw-delay-0001', 'provider-delay-0001', 'go');

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(FlowRunStatus::WaitingDelay, $run->status);
        $run->forceFill(['waiting_until' => now()->subSecond()])->save();

        app(CommunicationFlowExecutor::class)->advance((int) $run->id);
        app(CommunicationFlowExecutor::class)->advance((int) $run->id);

        $run->refresh();
        $this->assertSame(FlowRunStatus::Completed, $run->status);
        $this->assertSame(1, CommunicationFlowRun::query()->withoutGlobalScopes()
            ->where('conversation_id', $run->conversation_id)
            ->where('status', FlowRunStatus::Completed->value)
            ->count());
    }

    public function test_executor_jobs_are_registered(): void
    {
        $this->assertTrue(class_exists(CorrelateCommunicationFlowEventJob::class));
        $this->assertTrue(class_exists(AdvanceCommunicationFlowRunJob::class));
    }

    public function test_pause_flow_stops_active_run_and_blocks_advance(): void
    {
        [$inbox, $conversation, $flow] = $this->seedFlow(
            active: true,
            enabled: true,
            graph: $this->twoMessageGraph(),
        );

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'flow_id' => $flow->id,
            'flow_version_id' => CommunicationFlowVersion::query()->withoutGlobalScopes()->value('id'),
            'binding_id' => CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->value('id'),
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 'm1',
            'context_encrypted' => [],
            'started_at' => now(),
        ]);

        $flow->forceFill(['status' => FlowStatus::Paused])->save();

        $beforeMessages = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count();
        $beforeOutbox = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->whereNotNull('effect_key')
            ->count();

        app(CommunicationFlowExecutor::class)->advance((int) $run->id);

        $run->refresh();
        $this->assertSame(FlowRunStatus::Stopped, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame($beforeMessages, CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count());
        $this->assertSame($beforeOutbox, CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->whereNotNull('effect_key')
            ->count());

        $event = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'COMMUNICATION_FLOW_RUN_STOPPED')
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('flow_paused', $event->payload['reason'] ?? null);
        $this->assertSame((int) $run->id, (int) ($event->payload['run_id'] ?? 0));
    }

    public function test_disable_binding_stops_active_run_and_blocks_advance(): void
    {
        [$inbox, $conversation, $flow, $binding] = $this->seedFlow(
            active: true,
            enabled: true,
            graph: $this->twoMessageGraph(),
        );

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'flow_id' => $flow->id,
            'flow_version_id' => CommunicationFlowVersion::query()->withoutGlobalScopes()->value('id'),
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Running,
            'current_node_id' => 'm1',
            'context_encrypted' => [],
            'started_at' => now(),
        ]);

        $binding->forceFill(['enabled' => false])->save();

        $beforeMessages = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count();

        app(CommunicationFlowExecutor::class)->advance((int) $run->id);

        $run->refresh();
        $this->assertSame(FlowRunStatus::Stopped, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame($beforeMessages, CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count());

        $event = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('type', 'COMMUNICATION_FLOW_RUN_STOPPED')
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('binding_disabled', $event->payload['reason'] ?? null);
    }

    public function test_waiting_input_answer_stops_when_flow_paused(): void
    {
        [$inbox, $conversation, $flow] = $this->seedFlow(
            active: true,
            enabled: true,
            graph: $this->questionGraph(),
        );

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'flow_id' => $flow->id,
            'flow_version_id' => CommunicationFlowVersion::query()->withoutGlobalScopes()->value('id'),
            'binding_id' => CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->value('id'),
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::WaitingInput,
            'current_node_id' => 'q',
            'context_encrypted' => [],
            'started_at' => now(),
            'waiting_until' => now()->addHour(),
        ]);

        CommunicationFlowRunStep::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'run_id' => $run->id,
            'node_id' => 'q',
            'node_type' => 'question',
            'seq' => 1,
            'status' => 'waiting_input',
            'effect_key' => 'flow:'.$run->id.':q:question_prompt',
            'entered_at' => now(),
            'result_meta' => [],
        ]);

        $flow->forceFill(['status' => FlowStatus::Paused])->save();

        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'office_id' => $inbox->office_id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Inbound,
            'kind' => 'TEXT',
            'source' => MessageSource::Gateway,
            'status' => 'DELIVERED',
            'body_encrypted' => 'sim',
            'provider_message_id' => 'answer-paused-1',
            'content_digest' => hash('sha256', 'sim'),
            'metadata' => [],
            'occurred_at' => now(),
        ]);

        $beforeMessages = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count();

        app(CommunicationFlowCorrelator::class)->correlateMessage(
            (int) $inbox->office_id,
            (int) $conversation->id,
            (int) $message->id,
            'gw:answer-paused-1',
        );

        $run->refresh();
        $this->assertSame(FlowRunStatus::Stopped, $run->status);
        $this->assertSame($beforeMessages, CommunicationMessage::query()->withoutGlobalScopes()
            ->where('source', MessageSource::FlowAutomation->value)
            ->count());
        $context = is_array($run->context_encrypted) ? $run->context_encrypted : [];
        $this->assertArrayNotHasKey('pending_branch', $context);
    }

    /**
     * @param  array<string, mixed>|null  $graph
     * @return array{0:CommunicationInbox,1:CommunicationConversation,2:CommunicationFlow,3:CommunicationFlowInboxBinding}
     */
    private function seedFlow(bool $active, bool $enabled, ?array $graph = null): array
    {
        $office = Office::factory()->create(['communication_enabled' => true]);
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Inbox Flow',
            'session_id' => 'session-flow-'.uniqid(),
            'status' => InboxStatus::Connected,
            'is_enabled' => true,
            'lock_version' => 1,
        ]);
        $contact = CommunicationContact::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Contato',
            'is_active' => true,
        ]);
        $address = '+5511988880001';
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => '****0001',
            'is_active' => true,
        ]);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'inbox_id' => $inbox->id,
            'identity_id' => $identity->id,
            'status' => 'OPEN',
            'lock_version' => 1,
        ]);
        $flow = CommunicationFlow::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'name' => 'Robô',
            'status' => $active ? FlowStatus::Active : FlowStatus::Paused,
            'lock_version' => 1,
        ]);
        $graph ??= $this->messageGraph();
        $version = CommunicationFlowVersion::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $flow->id,
            'version' => 1,
            'graph_encrypted' => $graph,
            'graph_digest' => hash('sha256', json_encode($graph)),
            'published_at' => now(),
        ]);
        $binding = CommunicationFlowInboxBinding::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'flow_id' => $flow->id,
            'inbox_id' => $inbox->id,
            'published_version_id' => $version->id,
            'enabled' => $enabled,
            'lock_version' => 1,
        ]);

        return [$inbox, $conversation, $flow, $binding];
    }

    private function postLiveInbound(CommunicationInbox $inbox, string $eventId, string $providerId, string $text): void
    {
        $this->postEvent($inbox, GatewayEventType::MessageReceived, $eventId, [
            'provider_message_id' => $providerId,
            'from' => '+5511988880001',
            'text' => $text,
            'direction' => 'INBOUND',
            'kind' => 'TEXT',
        ])->assertNoContent();
    }

    /** @param array<string, mixed> $payload */
    private function postEvent(CommunicationInbox $inbox, GatewayEventType $type, string $eventId, array $payload)
    {
        $enrich = static function (array $message): array {
            if (($message['kind'] ?? null) === 'TEXT') {
                $message['provider_type'] ??= 'conversation';
                $message['family'] ??= 'TEXT';
            }

            return $message;
        };
        if ($type === GatewayEventType::MessageReceived) {
            $payload = $enrich($payload);
        } elseif ($type === GatewayEventType::HistorySynced && is_array($payload['messages'] ?? null)) {
            $payload['messages'] = array_map($enrich, $payload['messages']);
        }
        $event = [
            'contract_version' => 'v1',
            'gateway_event_id' => $eventId,
            'session_id' => $inbox->session_id,
            'type' => $type->value,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];
        $path = '/api/internal/v1/communication/gateway/events';
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = app(CommunicationHmacSigner::class)->headers('POST', $path, $body);

        return $this->json('POST', $path, $event, $headers, JSON_UNESCAPED_SLASHES);
    }

    /** @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>} */
    private function messageGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Olá automático']],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm'],
                ['id' => 'e2', 'source' => 'm', 'target' => 'e'],
            ],
        ];
    }

    /** @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>} */
    private function messageThenEndGraph(): array
    {
        return $this->messageGraph();
    }

    /** @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>} */
    private function delayGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'd', 'type' => 'delay', 'data' => ['duration_seconds' => 1]],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'd'],
                ['id' => 'e2', 'source' => 'd', 'target' => 'e'],
            ],
        ];
    }

    /** @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>} */
    private function twoMessageGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'm1', 'type' => 'message', 'data' => ['body' => 'Primeira']],
                ['id' => 'm2', 'type' => 'message', 'data' => ['body' => 'Segunda']],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'm1'],
                ['id' => 'e2', 'source' => 'm1', 'target' => 'm2'],
                ['id' => 'e3', 'source' => 'm2', 'target' => 'e'],
            ],
        ];
    }

    /** @return array{nodes: list<array<string,mixed>>, edges: list<array<string,mixed>>} */
    private function questionGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 's', 'type' => 'start', 'data' => []],
                ['id' => 'q', 'type' => 'question', 'data' => [
                    'prompt' => 'Confirma?',
                    'options' => ['sim', 'nao'],
                ]],
                ['id' => 'm', 'type' => 'message', 'data' => ['body' => 'Ok']],
                ['id' => 'e', 'type' => 'end', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 's', 'target' => 'q'],
                ['id' => 'e2', 'source' => 'q', 'target' => 'm', 'label' => 'sim'],
                ['id' => 'e3', 'source' => 'q', 'target' => 'e', 'label' => 'nao'],
                ['id' => 'e4', 'source' => 'm', 'target' => 'e'],
            ],
        ];
    }
}

final class FlowRuntimeTransport implements CommunicationTransport
{
    public bool $autoAccept = true;

    /** @var list<GatewayCommandData> */
    public array $dispatched = [];

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        $this->dispatched[] = $command;
        if (! $this->autoAccept) {
            throw new CommunicationTransportException('GATEWAY_TEMPORARY', true);
        }

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
}
