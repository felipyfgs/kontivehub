<?php

namespace App\Services\Communication\Outbox;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayContractPayload;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\OutboxStatus;
use App\Exceptions\ApiDomainException;
use App\Exceptions\CommunicationTransportException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationMessage;
use App\Models\CommunicationMessageBatch;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Automation\FiscalDispatchStatusProjector;
use App\Services\Communication\Availability;
use App\Services\Communication\Conversation\OutboundConversationGate;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowExecutor;
use App\Services\Communication\Gateway\GatewayOperationPolicy;
use App\Services\Communication\MessageAvailability;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class OutboxDispatcher
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private CommunicationTransport $transport,
        private FiscalDispatchStatusProjector $fiscalStatuses,
        private Availability $availability,
        private MessageAvailability $messageAvailability,
        private OutboundConversationGate $outboundConversationGate,
        private GatewayOperationPolicy $policy,
        private OutboundReadReceiptReleaseService $readReceipts,
        private EventRecorder $events,
    ) {}

    public function dispatch(int $entryId): void
    {
        $entry = $this->claim($entryId);
        if ($entry === null) {
            return;
        }

        try {
            $this->assertDispatchAllowed($entry);
            $providerMessageId = GatewayContractPayload::usesCommandIdAsProviderMessageId($entry->type)
                ? $entry->command_id
                : $entry->message?->provider_message_id;
            if ($providerMessageId === null && GatewayContractPayload::requiresProviderMessageId($entry->type)) {
                $providerMessageId = $entry->command_id;
            }
            $command = new GatewayCommandData(
                commandId: $entry->command_id,
                sessionId: $entry->session_id,
                type: $entry->type,
                payload: $entry->payload_encrypted ?? [],
                providerMessageId: $providerMessageId,
            );
            $this->transport->dispatch($command);
            $this->markAccepted($entryId);
        } catch (CommunicationTransportException $error) {
            $this->markFailure($entryId, $error->errorCode, $error->retryable);
        } catch (ApiDomainException $error) {
            $this->markFailure($entryId, $error->stableCode(), false);
        } catch (InvalidArgumentException) {
            $this->markFailure($entryId, 'GATEWAY_COMMAND_INVALID', false);
        } catch (Throwable) {
            $this->markFailure($entryId, 'GATEWAY_UNEXPECTED_FAILURE', true);
        }
    }

    private function claim(int $entryId): ?CommunicationOutboxEntry
    {
        return DB::transaction(function () use ($entryId): ?CommunicationOutboxEntry {
            $entry = CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->with([
                    'inbox' => fn ($query) => $query->withoutGlobalScopes()->with('tenant'),
                    'message' => fn ($query) => $query->withoutGlobalScopes(),
                    'messageBatch' => fn ($query) => $query->withoutGlobalScopes()->with([
                        'messages' => fn ($messages) => $messages->withoutGlobalScopes(),
                    ]),
                ])
                ->lockForUpdate()
                ->find($entryId);
            if ($entry === null || in_array($entry->status, [
                OutboxStatus::Accepted,
                OutboxStatus::Dead,
                OutboxStatus::Canceled,
            ], true)) {
                return null;
            }
            if ($entry->available_at?->isFuture()) {
                return null;
            }
            if ($entry->status === OutboxStatus::Dispatching
                && $entry->locked_at?->isAfter(now()->subMinutes(2))) {
                return null;
            }

            $entry->forceFill([
                'status' => OutboxStatus::Dispatching,
                'attempt_count' => (int) $entry->attempt_count + 1,
                'locked_at' => now(),
                'last_attempt_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->with([
                    'inbox' => fn ($query) => $query->withoutGlobalScopes()->with('tenant'),
                    'message' => fn ($query) => $query->withoutGlobalScopes(),
                    'messageBatch' => fn ($query) => $query->withoutGlobalScopes()->with([
                        'messages' => fn ($messages) => $messages->withoutGlobalScopes(),
                    ]),
                ])
                ->findOrFail($entry->id);
        });
    }

    private function assertDispatchAllowed(CommunicationOutboxEntry $entry): void
    {
        $inbox = $entry->inbox;
        if ($inbox === null) {
            if ($entry->type === GatewayCommandType::LogoutSession
                && $entry->inbox_id === null
                && $entry->message_id === null) {
                $this->availability->assertGatewayAvailable();

                return;
            }

            throw new CommunicationTransportException('OUTBOX_TENANT_SCOPE_INVALID', false);
        }

        if ((int) $entry->tenant_id !== (int) $inbox->tenant_id
            || (int) $entry->inbox_id !== (int) $inbox->id
            || ! hash_equals((string) $entry->session_id, (string) $inbox->session_id)) {
            throw new CommunicationTransportException('OUTBOX_TENANT_SCOPE_INVALID', false);
        }

        $message = $entry->message;
        if ($message !== null && (
            (int) $message->tenant_id !== (int) $entry->tenant_id
            || (int) $message->inbox_id !== (int) $entry->inbox_id
        )) {
            throw new CommunicationTransportException('OUTBOX_TENANT_SCOPE_INVALID', false);
        }
        $batch = $entry->messageBatch;
        if ($batch !== null && (
            (int) $batch->tenant_id !== (int) $entry->tenant_id
            || (int) $batch->inbox_id !== (int) $entry->inbox_id
            || $entry->type !== GatewayCommandType::SendMessageBatch
            || $batch->messages->count() !== (int) $batch->item_count
            || $batch->messages->contains(static fn (CommunicationMessage $child): bool => (int) $child->tenant_id !== (int) $entry->tenant_id
                || (int) $child->inbox_id !== (int) $entry->inbox_id
                || $child->purged_at !== null
                || $child->quarantined_at !== null
            )
        )) {
            throw new CommunicationTransportException('OUTBOX_TENANT_SCOPE_INVALID', false);
        }
        if (($entry->type === GatewayCommandType::SendMessage
                && ($message === null || $batch !== null))
            || ($entry->type === GatewayCommandType::SendMessageBatch
                && ($batch === null || $message !== null))
            || (! in_array($entry->type, [
                GatewayCommandType::SendMessage,
                GatewayCommandType::SendMessageBatch,
            ], true) && $batch !== null)) {
            throw new CommunicationTransportException('OUTBOX_TARGET_INVALID', false);
        }
        if ($message?->purged_at !== null) {
            throw new CommunicationTransportException('OUTBOX_MESSAGE_PURGED', false);
        }
        if ($message?->quarantined_at !== null) {
            throw new CommunicationTransportException('OUTBOX_MESSAGE_QUARANTINED', false);
        }
        if ($entry->type === GatewayCommandType::RequestMediaRetry
            && ($message === null || (
                ! $this->messageAvailability->isRecoverable($message)
                && ! $this->isMediaRescue($entry, $message)
            ))) {
            throw new CommunicationTransportException('MEDIA_RETRY_INVALID_REQUEST', false);
        }
        if ($entry->type === GatewayCommandType::SendMessage && (bool) data_get($message?->metadata, 'outbound_initiation')) {
            $identity = $message === null ? null : CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $entry->tenant_id)
                ->find($message->identity_id);
            if ($identity === null) {
                throw new CommunicationTransportException('OUTBOX_TENANT_SCOPE_INVALID', false);
            }
            $this->outboundConversationGate->assertAllowed($inbox, $identity);
        }

        if ($this->policy->allowsDisabledInbox($entry->type)) {
            $this->availability->assertGatewayAvailable();
        } else {
            $this->availability->assertEnabled(
                $inbox,
                $this->policy->requiresConnectedInbox($entry->type),
            );
        }
    }

    private function markAccepted(int $entryId): void
    {
        DB::transaction(function () use ($entryId): void {
            $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()->lockForUpdate()->find($entryId);
            if ($entry === null || $entry->status === OutboxStatus::Accepted) {
                return;
            }
            $acceptedAt = now();
            $entry->forceFill([
                'status' => OutboxStatus::Accepted,
                'accepted_at' => $acceptedAt,
                'locked_at' => null,
            ])->save();
            if ($entry->message_id !== null) {
                $message = CommunicationMessage::query()
                    ->withoutGlobalScopes()
                    ->whereKey($entry->message_id)
                    ->where('tenant_id', $entry->tenant_id)
                    ->where('inbox_id', $entry->inbox_id)
                    ->lockForUpdate()
                    ->first();
                if ($message instanceof CommunicationMessage
                    && $entry->type === GatewayCommandType::RequestMediaRetry) {
                    $metadata = is_array($message->metadata) ? $message->metadata : [];
                    if (strtoupper((string) ($metadata['media_state'] ?? '')) !== 'READY') {
                        $metadata['media_state'] = 'REQUESTED';
                        unset($metadata['media_error_code']);
                        $message->forceFill(['metadata' => $metadata])->save();
                        $this->recordMediaRetryState($entry, $message, 'REQUESTED');
                    }
                } elseif ($message instanceof CommunicationMessage
                    && $entry->type === GatewayCommandType::SendMessage) {
                    $current = $message->status instanceof MessageStatus
                        ? $message->status
                        : MessageStatus::from((string) $message->status);
                    $merged = $current->merge(MessageStatus::Accepted);
                    $message->forceFill([
                        'status' => $merged,
                        'accepted_at' => $message->accepted_at ?? $acceptedAt,
                    ])->save();
                    $this->fiscalStatuses->project($message, $merged, $acceptedAt, 'GATEWAY_ACCEPTANCE');
                }
            }
            if ($entry->message_batch_id !== null
                && $entry->type === GatewayCommandType::SendMessageBatch) {
                $batch = CommunicationMessageBatch::query()
                    ->withoutGlobalScopes()
                    ->whereKey($entry->message_batch_id)
                    ->where('tenant_id', $entry->tenant_id)
                    ->where('inbox_id', $entry->inbox_id)
                    ->lockForUpdate()
                    ->first();
                if ($batch instanceof CommunicationMessageBatch) {
                    $messages = CommunicationMessage::query()
                        ->withoutGlobalScopes()
                        ->where('message_batch_id', $batch->id)
                        ->orderBy('batch_position')
                        ->lockForUpdate()
                        ->get();
                    foreach ($messages as $message) {
                        $current = $message->status instanceof MessageStatus
                            ? $message->status
                            : MessageStatus::from((string) $message->status);
                        $merged = $current->merge(MessageStatus::Accepted);
                        $message->forceFill([
                            'status' => $merged,
                            'accepted_at' => $message->accepted_at ?? $acceptedAt,
                        ])->save();
                        $this->fiscalStatuses->project($message, $merged, $acceptedAt, 'GATEWAY_BATCH_ACCEPTANCE');
                    }
                    $batch->forceFill(['status' => 'ACCEPTED'])->save();
                }
            }
            if ($entry->type === GatewayCommandType::SendMessage) {
                try {
                    $this->readReceipts->release($entry);
                } catch (Throwable) {
                    $this->events->record(
                        tenantId: (int) $entry->tenant_id,
                        type: 'outbound.read_receipt.release_failed',
                        payload: [
                            'outbox_entry_id' => (int) $entry->id,
                            'message_id' => (int) $entry->message_id,
                            'code' => 'RELEASE_UNEXPECTED_FAILURE',
                        ],
                        inboxId: (int) $entry->inbox_id,
                        messageId: $entry->message_id !== null ? (int) $entry->message_id : null,
                    );
                }
            }
        });

        $accepted = CommunicationOutboxEntry::query()->withoutGlobalScopes()->find($entryId);
        if ($accepted instanceof CommunicationOutboxEntry && $accepted->status === OutboxStatus::Accepted) {
            app(FlowExecutor::class)->onOutboxAccepted($accepted);
        }
    }

    private function markFailure(int $entryId, string $code, bool $retryable): void
    {
        DB::transaction(function () use ($entryId, $code, $retryable): void {
            $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()->lockForUpdate()->find($entryId);
            if ($entry === null || $entry->status === OutboxStatus::Accepted) {
                return;
            }
            $terminal = ! $retryable || (int) $entry->attempt_count >= self::MAX_ATTEMPTS;
            $delay = min(300, 2 ** max(0, min(8, (int) $entry->attempt_count - 1)));
            $entry->forceFill([
                'status' => $terminal ? OutboxStatus::Dead : OutboxStatus::Retry,
                'available_at' => now()->addSeconds($delay),
                'locked_at' => null,
                'last_error_code' => mb_substr($code, 0, 80),
                'last_error_message' => null,
            ])->save();

            if ($terminal && $entry->message_id !== null) {
                $message = CommunicationMessage::query()
                    ->withoutGlobalScopes()
                    ->whereKey($entry->message_id)
                    ->where('tenant_id', $entry->tenant_id)
                    ->where('inbox_id', $entry->inbox_id)
                    ->lockForUpdate()
                    ->first();
                if ($message !== null) {
                    if ($entry->type === GatewayCommandType::RequestMediaRetry) {
                        $failureCode = $this->mediaRetryFailureCode($code);
                        $metadata = is_array($message->metadata) ? $message->metadata : [];
                        if (strtoupper((string) ($metadata['media_state'] ?? '')) !== 'READY') {
                            $metadata['media_state'] = 'FAILED';
                            $metadata['media_error_code'] = $failureCode;
                            $message->forceFill(['metadata' => $metadata])->save();
                            $this->recordMediaRetryState($entry, $message, 'FAILED', $failureCode);
                        }
                    } elseif ($this->isMessageAction($entry)) {
                        $metadata = is_array($message->metadata) ? $message->metadata : [];
                        $actions = is_array($metadata['gateway_actions'] ?? null) ? $metadata['gateway_actions'] : [];
                        $existing = is_array($actions[$entry->command_id] ?? null) ? $actions[$entry->command_id] : [];
                        if (in_array($existing['status'] ?? null, ['SUCCEEDED', 'FAILED'], true)) {
                            return;
                        }
                        $actions[$entry->command_id] = [
                            ...$existing,
                            'status' => 'FAILED',
                            'error_code' => $retryable ? 'ACTION_RETRY_EXHAUSTED' : 'ACTION_REJECTED',
                            'completed_at' => now()->toAtomString(),
                        ];
                        $metadata['gateway_actions'] = array_slice($actions, -10, null, true);
                        $message->forceFill(['metadata' => $metadata])->save();
                    } elseif ($entry->type === GatewayCommandType::SendMessage) {
                        $current = $message->status instanceof MessageStatus
                            ? $message->status
                            : MessageStatus::from((string) $message->status);
                        $incoming = $retryable ? MessageStatus::Unknown : MessageStatus::Failed;
                        $merged = $current->merge($incoming);
                        $message->forceFill([
                            'status' => $merged,
                            'failed_at' => $merged === MessageStatus::Failed
                                ? ($message->failed_at ?? now())
                                : null,
                        ])->save();
                        $this->fiscalStatuses->project($message, $merged, now(), 'GATEWAY_DISPATCH');
                    }
                }
            }
            if ($terminal && $entry->message_batch_id !== null
                && $entry->type === GatewayCommandType::SendMessageBatch) {
                $batch = CommunicationMessageBatch::query()
                    ->withoutGlobalScopes()
                    ->whereKey($entry->message_batch_id)
                    ->where('tenant_id', $entry->tenant_id)
                    ->where('inbox_id', $entry->inbox_id)
                    ->lockForUpdate()
                    ->first();
                if ($batch instanceof CommunicationMessageBatch) {
                    $incoming = $retryable ? MessageStatus::Unknown : MessageStatus::Failed;
                    $messages = CommunicationMessage::query()
                        ->withoutGlobalScopes()
                        ->where('message_batch_id', $batch->id)
                        ->orderBy('batch_position')
                        ->lockForUpdate()
                        ->get();
                    foreach ($messages as $message) {
                        $current = $message->status instanceof MessageStatus
                            ? $message->status
                            : MessageStatus::from((string) $message->status);
                        $merged = $current->merge($incoming);
                        $message->forceFill([
                            'status' => $merged,
                            'failed_at' => $merged === MessageStatus::Failed
                                ? ($message->failed_at ?? now())
                                : null,
                        ])->save();
                        $this->fiscalStatuses->project($message, $merged, now(), 'GATEWAY_BATCH_DISPATCH');
                    }
                    $batch->forceFill(['status' => $incoming->value])->save();
                }
            }
        });
    }

    private function mediaRetryFailureCode(string $code): string
    {
        return in_array($code, [
            'MEDIA_RETRY_STATE_MISSING',
            'MEDIA_RETRY_INVALID_REQUEST',
            'MEDIA_RETRY_DESCRIPTOR_INVALID',
            'MEDIA_RETRY_DESCRIPTOR_EXPIRED',
            'MEDIA_RETRY_NOT_AVAILABLE',
        ], true) ? $code : 'MEDIA_RETRY_REQUEST_FAILED';
    }

    private function isMessageAction(CommunicationOutboxEntry $entry): bool
    {
        return in_array($entry->type, [
            GatewayCommandType::EditMessage,
            GatewayCommandType::RevokeMessage,
            GatewayCommandType::ReactMessage,
        ], true);
    }

    private function isMediaRescue(
        CommunicationOutboxEntry $entry,
        CommunicationMessage $message,
    ): bool {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $kind = $message->kind?->value ?? $message->kind;

        return str_starts_with((string) $entry->effect_key, 'media-rescue:')
            && ($metadata['history'] ?? false) === true
            && trim((string) ($metadata['media_state'] ?? '')) === ''
            && in_array($kind, ['IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER'], true)
            && ! $message->attachments()->withoutGlobalScopes()->whereNull('purged_at')->exists();
    }

    private function recordMediaRetryState(
        CommunicationOutboxEntry $entry,
        CommunicationMessage $message,
        string $state,
        ?string $errorCode = null,
    ): void {
        $this->events->record(
            tenantId: (int) $entry->tenant_id,
            type: 'communication.media_retry.updated',
            payload: array_filter([
                'outbox_entry_id' => (int) $entry->id,
                'state' => $state,
                'direction' => $message->direction?->value ?? $message->direction,
                'kind' => $message->kind?->value ?? $message->kind,
                'error_code' => $errorCode,
            ], static fn (mixed $value): bool => $value !== null),
            inboxId: (int) $entry->inbox_id,
            conversationId: (int) $message->conversation_id,
            messageId: (int) $message->id,
        );
    }
}
