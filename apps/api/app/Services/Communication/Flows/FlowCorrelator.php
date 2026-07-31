<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageSource;
use App\Jobs\Communication\AdvanceCommunicationFlowRunJob;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationMessage;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Events\EventRecorder;
use Illuminate\Support\Facades\DB;

final class FlowCorrelator
{
    public function __construct(
        private readonly FlowAvailability $availability,
        private readonly FlowLock $locks,
        private readonly FlowConsumptionService $consumptions,
        private readonly EventRecorder $events,
        private readonly ConversationCanonicalizer $canonicalizer,
    ) {}

    public function correlateMessage(int $tenantId, int $conversationId, int $messageId, string $eventKey): void
    {
        if (! $this->availability->runtimeEnabled()) {
            return;
        }

        $advanceRunId = DB::transaction(function () use (
            $tenantId,
            $conversationId,
            $messageId,
            $eventKey,
        ): ?int {
            $requestedConversation = CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->find($conversationId);
            if ($requestedConversation === null) {
                return null;
            }
            $conversation = $this->canonicalizer->lockConversation($requestedConversation);

            $message = CommunicationMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->lockForUpdate()
                ->first();
            if ($message === null || ! $this->isEligibleInbound($message)) {
                return null;
            }

            $messageConversation = CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $message->inbox_id)
                ->find($message->conversation_id);
            if ($messageConversation === null
                || (int) $this->canonicalizer->conversation($messageConversation)->id
                    !== (int) $conversation->id) {
                return null;
            }

            if (! $this->consumptions->consumeOnce(
                tenantId: $tenantId,
                eventKey: $eventKey,
                conversationId: (int) $conversation->id,
                eventDigest: is_string($message->content_digest) ? $message->content_digest : null,
            )) {
                return null;
            }

            $active = $this->locks->findActiveRunForConversation((int) $conversation->id);

            if ($active !== null) {
                if ($active->status === FlowRunStatus::Paused) {
                    return null;
                }
                if ($active->status === FlowRunStatus::WaitingInput) {
                    if ($this->stopIfFlowOrBindingIneligible($active)) {
                        return null;
                    }
                    $this->applyQuestionAnswer($active, $message);

                    return (int) $active->id;
                }

                // Evento inbound com run ativo em outros estados: consumo registrado, sem avanço extra.
                return null;
            }

            $run = $this->startRunFromBinding($conversation);
            if ($run === null) {
                return null;
            }

            $this->consumptions->consumeOnce(
                tenantId: $tenantId,
                eventKey: $eventKey.'.run:'.$run->id,
                runId: (int) $run->id,
                conversationId: (int) $conversation->id,
            );

            return (int) $run->id;
        });

        if ($advanceRunId !== null) {
            AdvanceCommunicationFlowRunJob::dispatch($advanceRunId);
        }
    }

    private function isEligibleInbound(CommunicationMessage $message): bool
    {
        if ($message->direction !== MessageDirection::Inbound) {
            return false;
        }
        if ($message->source === MessageSource::FlowAutomation) {
            return false;
        }
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['history'] ?? false) === true) {
            return false;
        }
        if (($metadata['source'] ?? null) === 'flow_runtime') {
            return false;
        }

        return true;
    }

    private function startRunFromBinding(CommunicationConversation $conversation): ?CommunicationFlowRun
    {
        $binding = CommunicationFlowInboxBinding::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('inbox_id', $conversation->inbox_id)
            ->where('enabled', true)
            ->lockForUpdate()
            ->first();

        if ($binding === null || $binding->published_version_id === null) {
            return null;
        }

        $binding->loadMissing([
            'flow' => fn ($query) => $query->withoutGlobalScopes(),
            'publishedVersion' => fn ($query) => $query->withoutGlobalScopes(),
        ]);
        $flow = $binding->flow;
        if ($flow === null || $flow->status === FlowStatus::Paused) {
            return null;
        }

        $version = $binding->publishedVersion;
        if ($version === null) {
            return null;
        }

        $graph = is_array($version->graph_encrypted) ? $version->graph_encrypted : [];
        $startNodeId = $this->findStartNodeId($graph);
        if ($startNodeId === null) {
            return null;
        }

        $run = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $conversation->tenant_id,
            'flow_id' => $binding->flow_id,
            'flow_version_id' => $version->id,
            'binding_id' => $binding->id,
            'conversation_id' => $conversation->id,
            'status' => FlowRunStatus::Pending,
            'current_node_id' => $startNodeId,
            'context_encrypted' => [],
            'started_at' => now(),
        ]);

        $this->events->record(
            (int) $conversation->tenant_id,
            'COMMUNICATION_FLOW_RUN_STARTED',
            [
                'run_id' => (int) $run->id,
                'flow_id' => (int) $binding->flow_id,
                'flow_version_id' => (int) $version->id,
                'binding_id' => (int) $binding->id,
                'conversation_id' => (int) $conversation->id,
                'graph_digest' => (string) $version->graph_digest,
            ],
            inboxId: (int) $conversation->inbox_id,
            conversationId: (int) $conversation->id,
        );

        return $run;
    }

    private function applyQuestionAnswer(CommunicationFlowRun $run, CommunicationMessage $message): void
    {
        $run = $this->locks->lockRun((int) $run->id);
        if ($run->status !== FlowRunStatus::WaitingInput) {
            return;
        }

        if ($this->stopIfFlowOrBindingIneligible($run)) {
            return;
        }

        $version = $run->version()->withoutGlobalScopes()->first();
        $graph = is_array($version?->graph_encrypted) ? $version->graph_encrypted : [];
        $node = $this->findNode($graph, (string) $run->current_node_id);
        if ($node === null || ($node['type'] ?? '') !== 'question') {
            return;
        }

        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $body = trim((string) ($message->body_encrypted ?? ''));
        $matched = null;
        foreach ($options as $option) {
            if (! is_string($option)) {
                continue;
            }
            if (mb_strtolower(trim($option)) === mb_strtolower($body)) {
                $matched = trim($option);
                break;
            }
        }
        if ($matched === null) {
            // Resposta fora da allowlist: mantém waiting_input (sem avançar).
            return;
        }

        $context = is_array($run->context_encrypted) ? $run->context_encrypted : [];
        $answers = is_array($context['answers'] ?? null) ? $context['answers'] : [];
        $answers[(string) $run->current_node_id] = [
            'option' => $matched,
            'message_id' => (int) $message->id,
        ];
        $context['answers'] = $answers;
        $context['last_inbound_text'] = $matched;
        $context['pending_branch'] = $matched;

        $run->forceFill([
            'context_encrypted' => $context,
            'status' => FlowRunStatus::Running,
            'waiting_until' => null,
        ])->save();
    }

    /**
     * Fail-closed: pausa de fluxo / binding desabilitado encerra o run sem aplicar resposta.
     */
    private function stopIfFlowOrBindingIneligible(CommunicationFlowRun $run): bool
    {
        $flow = CommunicationFlow::query()
            ->withoutGlobalScopes()
            ->find($run->flow_id);
        $binding = CommunicationFlowInboxBinding::query()
            ->withoutGlobalScopes()
            ->find($run->binding_id);

        $reason = null;
        if ($flow?->status === FlowStatus::Paused) {
            $reason = 'flow_paused';
        } elseif ($binding?->enabled !== true) {
            $reason = 'binding_disabled';
        }

        if ($reason === null) {
            return false;
        }

        $run->forceFill([
            'status' => FlowRunStatus::Stopped,
            'finished_at' => now(),
            'waiting_until' => null,
            'waiting_effect_key' => null,
            'waiting_outbox_entry_id' => null,
        ])->save();

        $this->events->record(
            (int) $run->tenant_id,
            'COMMUNICATION_FLOW_RUN_STOPPED',
            [
                'run_id' => (int) $run->id,
                'flow_id' => (int) $run->flow_id,
                'conversation_id' => $run->conversation_id !== null ? (int) $run->conversation_id : null,
                'status' => FlowRunStatus::Stopped->value,
                'reason' => $reason,
            ],
            conversationId: $run->conversation_id !== null ? (int) $run->conversation_id : null,
        );

        return true;
    }

    /** @param array<string, mixed> $graph */
    private function findStartNodeId(array $graph): ?string
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'start' && is_string($node['id'] ?? null)) {
                return (string) $node['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>|null
     */
    private function findNode(array $graph, string $nodeId): ?array
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        foreach ($nodes as $node) {
            if (is_array($node) && ($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }
}
