<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowRunStepStatus;
use App\Enums\Communication\FlowStatus;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\OutboxStatus;
use App\Jobs\Communication\AdvanceCommunicationFlowRunJob;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationFlowRunStep;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommunicationFlowExecutor
{
    public function __construct(
        private readonly CommunicationFlowAvailability $availability,
        private readonly CommunicationFlowLock $locks,
        private readonly CommunicationOutboxService $outbox,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function advance(int $runId): void
    {
        if (! $this->availability->runtimeEnabled()) {
            return;
        }

        $followUpDelay = null;
        $shouldContinue = false;

        DB::transaction(function () use ($runId, &$followUpDelay, &$shouldContinue): void {
            $preview = CommunicationFlowRun::query()
                ->withoutGlobalScopes()
                ->find($runId);
            if ($preview === null
                || $preview->conversation_id === null
                || $preview->status->isTerminal()
                || $preview->status === FlowRunStatus::Paused) {
                return;
            }

            $conversation = $this->locks->lockConversation((int) $preview->conversation_id);
            $run = $this->locks->lockRun($runId);
            if ((int) $run->conversation_id !== (int) $conversation->id
                || $conversation->merged_into_conversation_id !== null) {
                throw new \RuntimeException('FLOW_RUN_CONVERSATION_MISMATCH');
            }

            if ($this->stopIfFlowOrBindingIneligible($run)) {
                return;
            }

            if ($run->status === FlowRunStatus::WaitingOutbox) {
                if (! $this->resolveWaitingOutbox($run)) {
                    return;
                }
            }

            if ($run->status === FlowRunStatus::WaitingDelay) {
                if ($run->waiting_until !== null && $run->waiting_until->isFuture()) {
                    return;
                }
                $this->completeWaitingDelay($run);
            }

            if ($run->status === FlowRunStatus::WaitingInput) {
                return;
            }

            if (! $run->status->canAdvance() && $run->status !== FlowRunStatus::Running && $run->status !== FlowRunStatus::Pending) {
                return;
            }

            $run->loadMissing([
                'version' => fn ($query) => $query->withoutGlobalScopes(),
                'conversation' => fn ($query) => $query->withoutGlobalScopes(),
                'conversation.inbox' => fn ($query) => $query->withoutGlobalScopes(),
                'conversation.identity' => fn ($query) => $query->withoutGlobalScopes(),
            ]);
            $graph = is_array($run->version?->graph_encrypted) ? $run->version->graph_encrypted : [];
            $effectApplied = false;

            // Caminha nós internos até o primeiro efeito externo ou espera.
            for ($guard = 0; $guard < 32; $guard++) {
                $nodeId = (string) ($run->current_node_id ?? '');
                $node = $this->findNode($graph, $nodeId);
                if ($node === null) {
                    $this->failRun($run, 'missing_node');

                    return;
                }

                $type = strtolower((string) ($node['type'] ?? ''));
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];

                if ($type === 'start') {
                    $this->enterAndCompleteStep($run, $nodeId, $type, null, ['phase' => 'start']);
                    $next = $this->nextNodeId($graph, $nodeId);
                    if ($next === null) {
                        $this->completeRun($run);

                        return;
                    }
                    $run->forceFill(['current_node_id' => $next, 'status' => FlowRunStatus::Running])->save();

                    continue;
                }

                if ($type === 'condition') {
                    $branch = $this->evaluateCondition($run, $data);
                    $this->enterAndCompleteStep($run, $nodeId, $type, null, ['branch' => $branch ? 'true' : 'false']);
                    $next = $this->nextNodeId($graph, $nodeId, $branch ? 'true' : 'false');
                    if ($next === null) {
                        $this->completeRun($run);

                        return;
                    }
                    $run->forceFill(['current_node_id' => $next, 'status' => FlowRunStatus::Running])->save();

                    continue;
                }

                if ($type === 'end') {
                    $this->enterAndCompleteStep($run, $nodeId, $type, null, ['phase' => 'end']);
                    $this->completeRun($run);

                    return;
                }

                if ($type === 'handoff') {
                    $this->applyHandoffEffect($run, $data);
                    $this->enterAndCompleteStep($run, $nodeId, $type, $this->effectKey($run, $nodeId, 'handoff'), [
                        'phase' => 'handoff',
                    ]);
                    $this->terminalStatus($run, FlowRunStatus::HandedOff, 'COMMUNICATION_FLOW_RUN_HANDED_OFF');

                    return;
                }

                if ($type === 'delay') {
                    $seconds = max(1, (int) ($data['duration_seconds'] ?? 1));
                    $max = max(1, (int) config('communication.flows.delay_max_seconds', 86_400));
                    $seconds = min($seconds, $max);
                    $effectKey = $this->effectKey($run, $nodeId, 'delay');
                    if ($this->stepExistsWithEffect($run, $effectKey)) {
                        $next = $this->nextNodeId($graph, $nodeId);
                        if ($next === null) {
                            $this->completeRun($run);

                            return;
                        }
                        $run->forceFill([
                            'current_node_id' => $next,
                            'status' => FlowRunStatus::Running,
                            'waiting_until' => null,
                            'waiting_effect_key' => null,
                        ])->save();

                        continue;
                    }
                    $this->enterStep($run, $nodeId, $type, $effectKey, FlowRunStepStatus::WaitingDelay, [
                        'duration_seconds' => $seconds,
                    ]);
                    $until = now()->addSeconds($seconds);
                    $run->forceFill([
                        'status' => FlowRunStatus::WaitingDelay,
                        'waiting_until' => $until,
                        'waiting_effect_key' => $effectKey,
                    ])->save();
                    $followUpDelay = $seconds;

                    return;
                }

                if ($type === 'question') {
                    $effectKey = $this->effectKey($run, $nodeId, 'question_prompt');
                    $promptSent = $this->stepExistsWithEffect($run, $effectKey);
                    if (! $promptSent) {
                        $prompt = trim((string) ($data['prompt'] ?? ''));
                        $this->sendTextMessage($run, $prompt, $effectKey, $nodeId, $type);

                        return;
                    }

                    // Prompt já enviado; se contexto tem pending_branch, avança.
                    $context = is_array($run->context_encrypted) ? $run->context_encrypted : [];
                    $branch = isset($context['pending_branch']) && is_string($context['pending_branch'])
                        ? $context['pending_branch']
                        : null;
                    if ($branch === null) {
                        if ($run->status !== FlowRunStatus::WaitingInput) {
                            $timeout = max(60, (int) config('communication.flows.question_timeout_seconds', 3_600));
                            $run->forceFill([
                                'status' => FlowRunStatus::WaitingInput,
                                'waiting_until' => $run->waiting_until ?? now()->addSeconds($timeout),
                            ])->save();
                        }

                        return;
                    }
                    unset($context['pending_branch']);
                    $run->forceFill(['context_encrypted' => $context])->save();
                    $this->completeOpenStep($run, $nodeId, ['answered' => true]);
                    $next = $this->nextNodeId($graph, $nodeId, $branch);
                    if ($next === null) {
                        $next = $this->nextNodeId($graph, $nodeId);
                    }
                    if ($next === null) {
                        $this->completeRun($run);

                        return;
                    }
                    $run->forceFill([
                        'current_node_id' => $next,
                        'status' => FlowRunStatus::Running,
                        'waiting_until' => null,
                        'waiting_effect_key' => null,
                    ])->save();

                    continue;
                }

                if (in_array($type, ['message', 'quick_reply'], true)) {
                    $effectKey = $this->effectKey($run, $nodeId, $type);
                    if ($this->stepExistsWithEffect($run, $effectKey)) {
                        if ($run->status === FlowRunStatus::WaitingOutbox) {
                            return;
                        }
                        $next = $this->nextNodeId($graph, $nodeId);
                        if ($next === null) {
                            $this->completeRun($run);

                            return;
                        }
                        $run->forceFill(['current_node_id' => $next, 'status' => FlowRunStatus::Running])->save();

                        continue;
                    }
                    $body = $this->resolveMessageBody($run, $type, $data);
                    $this->sendTextMessage($run, $body, $effectKey, $nodeId, $type);
                    $effectApplied = true;

                    return;
                }

                if ($type === 'action') {
                    $effectKey = $this->effectKey($run, $nodeId, 'action');
                    if ($this->stepExistsWithEffect($run, $effectKey)) {
                        $next = $this->nextNodeId($graph, $nodeId);
                        if ($next === null) {
                            $this->completeRun($run);

                            return;
                        }
                        $run->forceFill(['current_node_id' => $next, 'status' => FlowRunStatus::Running])->save();

                        continue;
                    }
                    $this->applyAction($run, $data);
                    $this->enterAndCompleteStep($run, $nodeId, $type, $effectKey, [
                        'kind' => (string) ($data['kind'] ?? ''),
                    ]);
                    $effectApplied = true;
                    $next = $this->nextNodeId($graph, $nodeId);
                    if ($next === null) {
                        $this->completeRun($run);

                        return;
                    }
                    $run->forceFill(['current_node_id' => $next, 'status' => FlowRunStatus::Running])->save();

                    // Uma ação externa por job — encerra após action.
                    return;
                }

                $this->failRun($run, 'unsupported_node_type');

                return;
            }

            $shouldContinue = ! $effectApplied && $run->status === FlowRunStatus::Running;
        });

        if ($followUpDelay !== null) {
            AdvanceCommunicationFlowRunJob::dispatch($runId)->delay(now()->addSeconds((int) $followUpDelay));
        } elseif ($shouldContinue) {
            AdvanceCommunicationFlowRunJob::dispatch($runId);
        }
    }

    public function onOutboxAccepted(CommunicationOutboxEntry $entry): void
    {
        if (! $this->availability->runtimeEnabled()) {
            return;
        }
        $effectKey = is_string($entry->effect_key) ? $entry->effect_key : null;
        if ($effectKey === null || $effectKey === '') {
            return;
        }

        $run = CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('waiting_outbox_entry_id', $entry->id)
            ->orWhere(function ($q) use ($effectKey): void {
                $q->where('waiting_effect_key', $effectKey)
                    ->where('status', FlowRunStatus::WaitingOutbox->value);
            })
            ->orderBy('id')
            ->first();

        if ($run === null) {
            $run = CommunicationFlowRun::query()
                ->withoutGlobalScopes()
                ->where('status', FlowRunStatus::WaitingOutbox->value)
                ->where('waiting_effect_key', $effectKey)
                ->first();
        }

        if ($run === null) {
            return;
        }

        AdvanceCommunicationFlowRunJob::dispatch((int) $run->id);
    }

    private function resolveWaitingOutbox(CommunicationFlowRun $run): bool
    {
        $entryId = $run->waiting_outbox_entry_id;
        $entry = $entryId !== null
            ? CommunicationOutboxEntry::query()->withoutGlobalScopes()->find($entryId)
            : null;
        if ($entry === null && is_string($run->waiting_effect_key)) {
            $entry = CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->where('effect_key', $run->waiting_effect_key)
                ->first();
        }
        if ($entry === null || $entry->status !== OutboxStatus::Accepted) {
            return false;
        }

        $nodeId = (string) $run->current_node_id;
        $this->completeOpenStep($run, $nodeId, [
            'outbox_entry_id' => (int) $entry->id,
            'outbox_status' => OutboxStatus::Accepted->value,
        ]);

        $graph = is_array($run->version?->graph_encrypted) ? $run->version->graph_encrypted : [];
        $node = $this->findNode($graph, $nodeId);
        $type = strtolower((string) ($node['type'] ?? ''));

        // question: após accepted do prompt, permanece waiting_input
        if ($type === 'question') {
            $timeout = max(60, (int) config('communication.flows.question_timeout_seconds', 3_600));
            $run->forceFill([
                'status' => FlowRunStatus::WaitingInput,
                'waiting_until' => now()->addSeconds($timeout),
                'waiting_outbox_entry_id' => null,
            ])->save();

            return false;
        }

        $next = $this->nextNodeId($graph, $nodeId);
        if ($next === null) {
            $this->completeRun($run);

            return false;
        }
        $run->forceFill([
            'current_node_id' => $next,
            'status' => FlowRunStatus::Running,
            'waiting_until' => null,
            'waiting_effect_key' => null,
            'waiting_outbox_entry_id' => null,
        ])->save();

        return true;
    }

    private function completeWaitingDelay(CommunicationFlowRun $run): void
    {
        $nodeId = (string) $run->current_node_id;
        $effectKey = is_string($run->waiting_effect_key) ? $run->waiting_effect_key : $this->effectKey($run, $nodeId, 'delay');
        if (! $this->stepExistsWithEffect($run, $effectKey)) {
            $this->enterAndCompleteStep($run, $nodeId, 'delay', $effectKey, ['phase' => 'delay_elapsed']);
        } else {
            $this->completeOpenStep($run, $nodeId, ['phase' => 'delay_elapsed']);
        }

        $graph = is_array($run->version?->graph_encrypted) ? $run->version->graph_encrypted : [];
        $next = $this->nextNodeId($graph, $nodeId);
        if ($next === null) {
            $this->completeRun($run);

            return;
        }
        $run->forceFill([
            'current_node_id' => $next,
            'status' => FlowRunStatus::Running,
            'waiting_until' => null,
            'waiting_effect_key' => null,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function resolveMessageBody(CommunicationFlowRun $run, string $type, array $data): string
    {
        if ($type === 'quick_reply' || isset($data['canned_response_id'])) {
            $cannedId = (int) ($data['canned_response_id'] ?? 0);
            if ($cannedId > 0) {
                $canned = CommunicationCannedResponse::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $run->tenant_id)
                    ->whereKey($cannedId)
                    ->first();
                if ($canned !== null) {
                    return trim((string) ($canned->body_encrypted ?? ''));
                }
            }
        }

        return trim((string) ($data['body'] ?? ''));
    }

    private function sendTextMessage(
        CommunicationFlowRun $run,
        string $body,
        string $effectKey,
        string $nodeId,
        string $nodeType,
    ): void {
        $existing = CommunicationOutboxEntry::query()
            ->withoutGlobalScopes()
            ->where('effect_key', $effectKey)
            ->first();
        if ($existing !== null) {
            $run->forceFill([
                'status' => FlowRunStatus::WaitingOutbox,
                'waiting_effect_key' => $effectKey,
                'waiting_outbox_entry_id' => $existing->id,
            ])->save();
            $this->enterStep($run, $nodeId, $nodeType, $effectKey, FlowRunStepStatus::WaitingOutbox, [
                'outbox_entry_id' => (int) $existing->id,
                'reused' => true,
            ]);

            return;
        }

        $conversation = $run->conversation;
        if ($conversation === null) {
            $this->failRun($run, 'missing_conversation');

            return;
        }
        $conversation->loadMissing([
            'inbox' => fn ($query) => $query->withoutGlobalScopes(),
            'identity' => fn ($query) => $query->withoutGlobalScopes(),
        ]);
        $providerId = 'flow-'.strtolower((string) Str::ulid());
        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'inbox_id' => $conversation->inbox_id,
            'conversation_id' => $conversation->id,
            'identity_id' => $conversation->identity_id,
            'direction' => MessageDirection::Outbound,
            'kind' => MessageKind::Text,
            'source' => MessageSource::FlowAutomation,
            'status' => MessageStatus::Queued,
            'body_encrypted' => $body !== '' ? $body : null,
            'provider_message_id' => $providerId,
            'content_digest' => hash('sha256', implode('|', ['TEXT', $body, $effectKey])),
            'metadata' => [
                'source' => 'flow_runtime',
                'flow_run_id' => (int) $run->id,
                'flow_node_id' => $nodeId,
                'effect_key' => $effectKey,
            ],
            'occurred_at' => now(),
        ]);

        $payload = [
            'to' => (string) $conversation->identity?->address_encrypted,
            'kind' => MessageKind::Text->value,
            'text' => $body,
        ];

        $entry = $this->outbox->enqueue(
            $conversation->inbox,
            GatewayCommandType::SendMessage,
            $payload,
            $message,
            commandId: $effectKey,
            effectKey: $effectKey,
        );

        $conversation->forceFill([
            'last_message_at' => $message->occurred_at,
            'lock_version' => (int) $conversation->lock_version + 1,
        ])->save();

        $this->enterStep($run, $nodeId, $nodeType, $effectKey, FlowRunStepStatus::WaitingOutbox, [
            'outbox_entry_id' => (int) $entry->id,
            'message_id' => (int) $message->id,
        ]);

        $run->forceFill([
            'status' => FlowRunStatus::WaitingOutbox,
            'waiting_effect_key' => $effectKey,
            'waiting_outbox_entry_id' => $entry->id,
        ])->save();

        $this->events->record(
            (int) $run->tenant_id,
            'COMMUNICATION_FLOW_MESSAGE_QUEUED',
            [
                'run_id' => (int) $run->id,
                'node_id' => $nodeId,
                'message_id' => (int) $message->id,
                'effect_key_digest' => hash('sha256', $effectKey),
            ],
            inboxId: (int) $conversation->inbox_id,
            conversationId: (int) $conversation->id,
            messageId: (int) $message->id,
        );
    }

    /** @param array<string, mixed> $data */
    private function applyAction(CommunicationFlowRun $run, array $data): void
    {
        $conversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->lockForUpdate()
            ->find($run->conversation_id);
        if ($conversation === null) {
            return;
        }
        $kind = strtolower((string) ($data['kind'] ?? ''));
        if ($kind === 'label') {
            $labelId = (int) ($data['label_id'] ?? 0);
            if ($labelId > 0) {
                $conversation->labels()->syncWithoutDetaching([$labelId => [
                    'tenant_id' => $conversation->tenant_id,
                    'assigned_by_membership_id' => null,
                ]]);
            }
        } elseif ($kind === 'assignee') {
            $membershipId = (int) ($data['assignee_membership_id'] ?? 0);
            if ($membershipId > 0) {
                $conversation->forceFill([
                    'assignee_membership_id' => $membershipId,
                    'lock_version' => (int) $conversation->lock_version + 1,
                ])->save();
            }
        } elseif ($kind === 'status') {
            $status = ConversationStatus::tryFrom(strtoupper((string) ($data['status'] ?? '')));
            if ($status !== null) {
                $conversation->forceFill([
                    'status' => $status,
                    'resolved_at' => $status === ConversationStatus::Resolved ? now() : $conversation->resolved_at,
                    'lock_version' => (int) $conversation->lock_version + 1,
                ])->save();
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function applyHandoffEffect(CommunicationFlowRun $run, array $data): void
    {
        $conversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->lockForUpdate()
            ->find($run->conversation_id);
        if ($conversation === null) {
            return;
        }
        $membershipId = (int) ($data['assignee_membership_id'] ?? 0);
        $updates = ['lock_version' => (int) $conversation->lock_version + 1];
        if ($membershipId > 0) {
            $updates['assignee_membership_id'] = $membershipId;
        }
        $conversation->forceFill($updates)->save();
    }

    /** @param array<string, mixed> $data */
    private function evaluateCondition(CommunicationFlowRun $run, array $data): bool
    {
        $field = (string) ($data['field'] ?? '');
        $operator = strtolower((string) ($data['operator'] ?? 'eq'));
        $expected = $data['value'] ?? null;
        $context = is_array($run->context_encrypted) ? $run->context_encrypted : [];
        $conversation = $run->conversation;
        $actual = match ($field) {
            'contact.name' => (string) ($conversation?->identity?->contact?->name ?? ''),
            'conversation.status' => (string) ($conversation?->status?->value ?? $conversation?->status ?? ''),
            'last_inbound_text' => (string) ($context['last_inbound_text'] ?? ''),
            default => '',
        };

        $expectedStr = is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected;
        if ($operator === 'contains') {
            return $expectedStr !== '' && str_contains(mb_strtolower($actual), mb_strtolower($expectedStr));
        }

        return mb_strtolower(trim($actual)) === mb_strtolower(trim($expectedStr));
    }

    private function effectKey(CommunicationFlowRun $run, string $nodeId, string $kind): string
    {
        return 'flow:'.$run->id.':'.$nodeId.':'.$kind;
    }

    private function stepExistsWithEffect(CommunicationFlowRun $run, string $effectKey): bool
    {
        return CommunicationFlowRunStep::query()
            ->withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('effect_key', $effectKey)
            ->exists();
    }

    /** @param array<string, mixed> $meta */
    private function enterAndCompleteStep(
        CommunicationFlowRun $run,
        string $nodeId,
        string $nodeType,
        ?string $effectKey,
        array $meta,
    ): void {
        $seq = $this->nextSeq($run, $nodeId);
        CommunicationFlowRunStep::query()->withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'seq' => $seq,
            'status' => FlowRunStepStatus::Completed,
            'effect_key' => $effectKey,
            'entered_at' => now(),
            'exited_at' => now(),
            'result_meta' => $meta,
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function enterStep(
        CommunicationFlowRun $run,
        string $nodeId,
        string $nodeType,
        ?string $effectKey,
        FlowRunStepStatus $status,
        array $meta,
    ): void {
        if ($effectKey !== null && $this->stepExistsWithEffect($run, $effectKey)) {
            return;
        }
        $seq = $this->nextSeq($run, $nodeId);
        CommunicationFlowRunStep::query()->withoutGlobalScopes()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'seq' => $seq,
            'status' => $status,
            'effect_key' => $effectKey,
            'entered_at' => now(),
            'result_meta' => $meta,
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function completeOpenStep(CommunicationFlowRun $run, string $nodeId, array $meta): void
    {
        $step = CommunicationFlowRunStep::query()
            ->withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('node_id', $nodeId)
            ->whereIn('status', [
                FlowRunStepStatus::Entered->value,
                FlowRunStepStatus::WaitingOutbox->value,
                FlowRunStepStatus::WaitingDelay->value,
                FlowRunStepStatus::WaitingInput->value,
                FlowRunStepStatus::Pending->value,
            ])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
        if ($step === null) {
            return;
        }
        $existingMeta = is_array($step->result_meta) ? $step->result_meta : [];
        $step->forceFill([
            'status' => FlowRunStepStatus::Completed,
            'exited_at' => now(),
            'result_meta' => array_merge($existingMeta, $meta),
        ])->save();
    }

    private function nextSeq(CommunicationFlowRun $run, string $nodeId): int
    {
        $max = (int) CommunicationFlowRunStep::query()
            ->withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('node_id', $nodeId)
            ->max('seq');

        return $max + 1;
    }

    private function completeRun(CommunicationFlowRun $run): void
    {
        $this->terminalStatus($run, FlowRunStatus::Completed, 'COMMUNICATION_FLOW_RUN_COMPLETED');
    }

    private function failRun(CommunicationFlowRun $run, string $code): void
    {
        $this->terminalStatus($run, FlowRunStatus::Failed, 'COMMUNICATION_FLOW_RUN_FAILED', ['code' => $code]);
    }

    /**
     * Fail-closed: fluxo pausado ou binding desabilitado interrompe o run sem efeitos.
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

        $this->terminalStatus($run, FlowRunStatus::Stopped, 'COMMUNICATION_FLOW_RUN_STOPPED', [
            'reason' => $reason,
        ]);

        return true;
    }

    /** @param array<string, mixed> $extra */
    private function terminalStatus(
        CommunicationFlowRun $run,
        FlowRunStatus $status,
        string $eventType,
        array $extra = [],
    ): void {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'waiting_until' => null,
            'waiting_effect_key' => null,
            'waiting_outbox_entry_id' => null,
        ])->save();
        $this->events->record(
            (int) $run->tenant_id,
            $eventType,
            array_merge([
                'run_id' => (int) $run->id,
                'flow_id' => (int) $run->flow_id,
                'conversation_id' => (int) $run->conversation_id,
                'status' => $status->value,
            ], $extra),
            inboxId: $run->conversation?->inbox_id !== null ? (int) $run->conversation->inbox_id : null,
            conversationId: $run->conversation_id !== null ? (int) $run->conversation_id : null,
        );
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>|null
     */
    private function findNode(array $graph, string $nodeId): ?array
    {
        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (is_array($node) && ($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $graph */
    private function nextNodeId(array $graph, string $sourceId, ?string $branch = null): ?string
    {
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $candidates = [];
        foreach ($edges as $edge) {
            if (! is_array($edge) || ($edge['source'] ?? null) !== $sourceId) {
                continue;
            }
            $label = $this->edgeBranch($edge);
            $candidates[] = ['target' => (string) ($edge['target'] ?? ''), 'label' => $label];
        }
        if ($candidates === []) {
            return null;
        }
        if ($branch !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate['label'] !== null
                    && mb_strtolower($candidate['label']) === mb_strtolower($branch)
                    && $candidate['target'] !== '') {
                    return $candidate['target'];
                }
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate['target'] !== '') {
                return $candidate['target'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $edge */
    private function edgeBranch(array $edge): ?string
    {
        foreach (['label', 'branch', 'sourceHandle'] as $key) {
            if (isset($edge[$key]) && is_string($edge[$key]) && trim($edge[$key]) !== '') {
                return trim($edge[$key]);
            }
        }
        $data = is_array($edge['data'] ?? null) ? $edge['data'] : [];
        if (isset($data['branch']) && is_string($data['branch']) && trim($data['branch']) !== '') {
            return trim($data['branch']);
        }

        return null;
    }
}
