<?php

namespace App\Services\Communication\Events;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayEventData;
use App\DTO\Communication\MessageSemanticContent;
use App\DTO\Communication\PayloadDigest;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\GatewayEventConflictException;
use App\Jobs\Communication\CorrelateFlowEventJob;
use App\Jobs\Communication\DeleteMediaObjectJob;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationConversation;
use App\Models\CommunicationEvent;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Services\Communication\Automation\FiscalDispatchStatusProjector;
use App\Services\Communication\Contact\InboxIdentityProfileMerger;
use App\Services\Communication\Conversation\ConversationReadStateService;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Flows\FlowAvailability;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\Pairing\PairingStateStore;
use App\Services\Communication\ProfilePicture\ProfilePictureRefreshScheduler;
use App\Services\Communication\WhatsAppAddressNormalizer;
use App\Services\Communication\WhatsAppPeerCorrelationService;
use App\Services\Communication\WhatsAppPeerResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class GatewayEventIngestor
{
    public function __construct(
        private WhatsAppAddressNormalizer $normalizer,
        private WhatsAppPeerResolver $peerResolver,
        private WhatsAppPeerCorrelationService $peerCorrelation,
        private CommunicationTransport $transport,
        private MediaStore $media,
        private PairingStateStore $pairing,
        private EventRecorder $events,
        private FiscalDispatchStatusProjector $fiscalStatuses,
        private ConversationCanonicalizer $peerCanonicalizer,
        private InboxIdentityProfileMerger $identityProfiles,
        private ConversationReadStateService $readState,
        private ProfilePictureRefreshScheduler $profilePictures,
    ) {}

    /** @return 'processed'|'duplicate' */
    public function ingest(GatewayEventData $incoming): string
    {
        $digest = PayloadDigest::make($incoming->toArray());
        $existing = CommunicationEvent::query()->withoutGlobalScopes()
            ->where('gateway_event_id', $incoming->gatewayEventId)
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_digest, $digest)) {
                throw new GatewayEventConflictException('Gateway event ID reutilizado com conteúdo diferente.');
            }

            return 'duplicate';
        }

        $inbox = CommunicationInbox::query()->withoutGlobalScope('tenant')
            ->where('session_id', $incoming->sessionId)
            ->first();
        if ($inbox === null) {
            return 'ignored';
        }

        $storedMedia = null;
        if (in_array($incoming->type, [GatewayEventType::MessageReceived, GatewayEventType::MediaRetryUpdated], true)
            && is_string($incoming->payload['spool_id'] ?? null)) {
            $retry = $incoming->type === GatewayEventType::MediaRetryUpdated;
            $expectedSha = strtolower((string) ($incoming->payload[$retry ? 'sha256' : 'media_sha256'] ?? ''));
            $expectedSize = (int) ($incoming->payload[$retry ? 'size_bytes' : 'media_size_bytes'] ?? -1);
            if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha) || $expectedSize < 0) {
                throw new RuntimeException('Descriptor de mídia do gateway inválido.');
            }
            $storedMedia = $this->media->putStream(
                $this->transport->downloadMedia((string) $incoming->payload['spool_id']),
                [
                    'tenant_id' => (int) $inbox->tenant_id,
                    'inbox_id' => (int) $inbox->id,
                    'gateway_event_id' => $incoming->gatewayEventId,
                    'sha256' => $expectedSha,
                ],
            );
            if ($storedMedia['size_bytes'] !== $expectedSize || ! hash_equals($expectedSha, $storedMedia['sha256'])) {
                $this->media->delete($storedMedia['object_id']);
                throw new RuntimeException('Mídia recebida não corresponde ao descriptor do gateway.');
            }
        }

        try {
            /** @var array{tenant_id:int,conversation_id:int,message_id:int,event_key:string}|null $flowCorrelation */
            $flowCorrelation = null;
            $attempts = in_array($incoming->type, [
                GatewayEventType::MessageReceived,
                GatewayEventType::MessageActionReceived,
                GatewayEventType::HistorySynced,
            ], true) ? 3 : 1;
            $result = DB::transaction(function () use ($incoming, $digest, $inbox, $storedMedia, &$flowCorrelation): string {
                $flowCorrelation = null;
                $this->lockAdvisory('gateway-event', $incoming->gatewayEventId);
                $duplicate = CommunicationEvent::query()->withoutGlobalScopes()
                    ->where('gateway_event_id', $incoming->gatewayEventId)
                    ->first();
                if ($duplicate !== null) {
                    if (! hash_equals((string) $duplicate->payload_digest, $digest)) {
                        throw new GatewayEventConflictException('Gateway event ID reutilizado com conteúdo diferente.');
                    }

                    return 'duplicate';
                }

                [$conversationId, $messageId, $safePayload] = match ($incoming->type) {
                    GatewayEventType::MessageReceived => $this->ingestInbound($incoming, $inbox, $storedMedia),
                    GatewayEventType::MessageStatusChanged => $this->ingestReceipt($incoming, $inbox),
                    GatewayEventType::MessageActionReceived => $this->ingestMessageAction($incoming, $inbox),
                    GatewayEventType::SessionStatusChanged => $this->ingestSessionStatus($incoming, $inbox),
                    GatewayEventType::PairingUpdated => $this->ingestPairing($incoming, $inbox),
                    GatewayEventType::MediaReady => [null, null, ['media_ready' => true]],
                    GatewayEventType::HistorySynced => $this->ingestHistory($incoming, $inbox),
                    GatewayEventType::MediaRetryUpdated => $this->ingestMediaRetry($incoming, $inbox, $storedMedia),
                    GatewayEventType::ChatPresenceChanged,
                    GatewayEventType::ContactPresenceChanged => $this->ingestPresenceSignal($incoming, $inbox),
                    GatewayEventType::ContactProfileChanged => $this->ingestContactProfile($incoming, $inbox),
                    GatewayEventType::ContactIdentityChanged => $this->ingestIdentityChange($incoming, $inbox),
                    GatewayEventType::PrivacySettingsChanged,
                    GatewayEventType::BlocklistChanged,
                    GatewayEventType::ChatStateChanged,
                    GatewayEventType::SyncStatusChanged,
                    GatewayEventType::GatewayAlert => [null, null, $this->allowlistedStatePayload($incoming)],
                };

                if ($incoming->type === GatewayEventType::MessageReceived
                    && $conversationId !== null
                    && $messageId !== null
                    && ($safePayload['created'] ?? false) === true
                    && ($safePayload['history'] ?? false) !== true
                    && strtoupper((string) ($safePayload['direction'] ?? 'INBOUND')) === 'INBOUND'
                ) {
                    $flowCorrelation = [
                        'tenant_id' => (int) $inbox->tenant_id,
                        'conversation_id' => (int) $conversationId,
                        'message_id' => (int) $messageId,
                        'event_key' => 'gw:'.$incoming->gatewayEventId,
                    ];
                }

                $this->events->record(
                    tenantId: (int) $inbox->tenant_id,
                    type: $incoming->type->value,
                    payload: $safePayload,
                    inboxId: (int) $inbox->id,
                    conversationId: $conversationId,
                    messageId: $messageId,
                    gatewayEventId: $incoming->gatewayEventId,
                    payloadDigest: $digest,
                    occurredAt: $incoming->occurredAt,
                );

                return 'processed';
            }, $attempts);
            if ($result === 'duplicate' && $storedMedia !== null) {
                $this->media->delete($storedMedia['object_id']);
            }

            if ($result === 'processed' && $flowCorrelation !== null
                && app(FlowAvailability::class)->runtimeEnabled()) {
                CorrelateFlowEventJob::dispatch(
                    $flowCorrelation['tenant_id'],
                    $flowCorrelation['conversation_id'],
                    $flowCorrelation['message_id'],
                    $flowCorrelation['event_key'],
                );
            }

            return $result;
        } catch (Throwable $error) {
            if ($storedMedia !== null) {
                $this->media->delete($storedMedia['object_id']);
            }
            throw $error;
        }
    }

    /**
     * @param  array{object_id:string,size_bytes:int,sha256:string}|null  $storedMedia
     * @return array{0:int,1:int,2:array<string,mixed>}
     */
    private function ingestInbound(GatewayEventData $incoming, CommunicationInbox $inbox, ?array $storedMedia): array
    {
        $history = (bool) ($incoming->payload['history'] ?? false);
        $providerId = (string) ($incoming->payload['provider_message_id'] ?? '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $providerId)) {
            throw new RuntimeException('provider_message_id inválido.');
        }
        $this->lockAdvisory('provider-message', $inbox->id.'|'.$providerId);
        $occurredAt = isset($incoming->payload['occurred_at'])
            ? Carbon::parse((string) $incoming->payload['occurred_at'])->toImmutable()
            : Carbon::instance($incoming->occurredAt)->toImmutable();
        $existing = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $providerId)
            ->first();

        try {
            $address = $this->peerResolver->resolve($incoming->payload, $inbox);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException($error->getMessage(), previous: $error);
        }
        $aliases = $this->peerResolver->aliases($incoming->payload, $address, $inbox);
        [$identity, $conversation] = $this->peerCorrelation->correlate(
            $inbox,
            $address,
            $aliases,
            $history,
            $occurredAt,
            $existing,
        );
        $existing = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $providerId)
            ->lockForUpdate()
            ->first();
        $payload = $incoming->payload;
        $kind = MessageKind::from(strtoupper((string) ($payload['kind'] ?? '')));
        $providerType = (string) $payload['provider_type'];
        $content = MessageSemanticContent::fromEvent($payload, $kind);
        $body = trim((string) ($payload['text'] ?? $payload['caption'] ?? ''));
        $direction = match (strtoupper((string) ($incoming->payload['direction'] ?? 'INBOUND'))) {
            'OUTBOUND' => MessageDirection::Outbound,
            'INTERNAL' => MessageDirection::Internal,
            default => MessageDirection::Inbound,
        };
        $replyTo = null;
        $replyProviderId = (string) ($incoming->payload['reply_to_provider_message_id']
            ?? data_get($incoming->payload, 'reply_to.provider_message_id')
            ?? '');
        if ($replyProviderId !== '') {
            $replyTo = CommunicationMessage::query()->withoutGlobalScopes()
                ->where('inbox_id', $inbox->id)
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $replyProviderId)
                ->value('id');
        }
        $metadata = array_filter([
            'history' => $history ?: null,
            'ephemeral' => ($incoming->payload['ephemeral'] ?? false) ?: null,
            'view_once' => ($incoming->payload['view_once'] ?? false) ?: null,
            'media_state' => is_string($incoming->payload['media_state'] ?? null)
                ? $incoming->payload['media_state']
                : null,
            'media_error_code' => is_string($incoming->payload['media_error_code'] ?? null)
                ? $incoming->payload['media_error_code']
                : null,
        ], static fn (mixed $value): bool => $value !== null);
        if ($storedMedia !== null) {
            $metadata['media_state'] = 'READY';
            unset($metadata['media_error_code']);
        }

        if ($existing !== null) {
            if ((int) $existing->conversation_id !== (int) $conversation->id) {
                throw new GatewayEventConflictException(
                    'Provider message ID reutilizado para outro peer.',
                );
            }

            $enriched = $this->enrichExistingMessage(
                $existing,
                $conversation,
                $direction,
                $kind,
                $providerType,
                $body,
                $content,
                $replyTo !== null ? (int) $replyTo : null,
                $metadata,
                $storedMedia,
                $incoming,
                $inbox,
                $occurredAt,
            );

            return [(int) $conversation->id, (int) $existing->id, [
                'history' => $history,
                'direction' => $direction->value,
                'created' => false,
                'enriched' => $enriched,
            ]];
        }

        $message = CommunicationMessage::query()->withoutGlobalScopes()->create([
            'tenant_id' => $inbox->tenant_id,
            'inbox_id' => $inbox->id,
            'conversation_id' => $conversation->id,
            'identity_id' => $identity->id,
            'reply_to_message_id' => $replyTo,
            'direction' => $direction,
            'kind' => $kind,
            'provider_type' => $providerType,
            'source' => MessageSource::Gateway,
            'status' => $direction === MessageDirection::Inbound ? MessageStatus::Delivered : MessageStatus::Sent,
            'body_encrypted' => $body !== '' ? $body : null,
            'content_encrypted' => $content !== [] ? $content : null,
            'provider_message_id' => $providerId,
            'gateway_event_id' => $incoming->gatewayEventId,
            'content_digest' => hash('sha256', implode('|', [
                $kind->value,
                $providerType,
                $body,
                PayloadDigest::make($content),
                $storedMedia['sha256'] ?? '',
            ])),
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
            'sent_at' => $direction === MessageDirection::Inbound ? null : $occurredAt,
            'delivered_at' => $direction === MessageDirection::Inbound ? $occurredAt : null,
        ]);

        if ($storedMedia !== null) {
            $this->upsertAttachment($message, $inbox, $incoming, $storedMedia, false);
        }
        if (! $history) {
            $conversation->forceFill([
                'status' => ConversationStatus::Open,
                'snoozed_until' => null,
                'last_message_at' => $conversation->last_message_at === null
                    || $conversation->last_message_at->isBefore($occurredAt)
                        ? $occurredAt
                        : $conversation->last_message_at,
                'lock_version' => (int) $conversation->lock_version + 1,
            ])->save();
            if ($direction === MessageDirection::Inbound) {
                $this->readState->registerLiveInbound($conversation, $message);
            }
        } elseif ($conversation->last_message_at === null || $conversation->last_message_at->isBefore($occurredAt)) {
            $conversation->forceFill(['last_message_at' => $occurredAt])->save();
        }

        return [(int) $conversation->id, (int) $message->id, [
            'kind' => $kind->value,
            'provider_type' => $providerType,
            'has_media' => $storedMedia !== null,
            'history' => $history,
            'direction' => $direction->value,
            'created' => true,
        ]];
    }

    /** @return array{0:?int,1:?int,2:array<string,mixed>} */
    private function ingestMessageAction(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $targetProviderId = (string) ($incoming->payload['target_message_id']
            ?? $incoming->payload['target_provider_message_id']
            ?? '');
        $action = strtoupper((string) ($incoming->payload['action'] ?? ''));
        if ($targetProviderId === '' || ! in_array($action, ['EDIT', 'REVOKE', 'REACTION', 'POLL_VOTE', 'INTERACTIVE_RESPONSE'], true)) {
            throw new RuntimeException('Ação de mensagem do gateway inválida.');
        }

        $messagePreview = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $targetProviderId)
            ->first();
        if ($messagePreview === null) {
            return [null, null, ['action' => $action, 'target_message_id' => $targetProviderId, 'orphan' => true]];
        }

        try {
            $sender = $this->peerResolver->resolve($incoming->payload, $inbox);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException($error->getMessage(), previous: $error);
        }
        $aliases = $this->peerResolver->aliases($incoming->payload, $sender, $inbox);
        [$canonicalIdentity, $canonicalConversation] = $this->peerCorrelation->correlate(
            $inbox,
            $sender,
            $aliases,
            true,
            $incoming->occurredAt,
            $messagePreview,
        );
        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $targetProviderId)
            ->lockForUpdate()
            ->firstOrFail();
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()->find($message->identity_id);
        if ($identity === null
            || ((int) $identity->id !== (int) $canonicalIdentity->id
                && (int) $identity->canonical_identity_id !== (int) $canonicalIdentity->id)) {
            throw new RuntimeException('Ação não pertence à identidade da mensagem alvo.');
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $content = is_array($message->content_encrypted) ? $message->content_encrypted : [];
        $actorKey = hash('sha256', $sender);
        switch ($action) {
            case 'EDIT':
                $text = trim((string) ($incoming->payload['text'] ?? ''));
                if ($text === '') {
                    throw new RuntimeException('Edição recebida sem conteúdo.');
                }
                $message->body_encrypted = $text;
                $content['text'] = $text;
                $metadata['edited_at'] = $incoming->occurredAt->format(DATE_ATOM);
                break;
            case 'REVOKE':
                $metadata['revoked'] = true;
                $message->revoked_at = $incoming->occurredAt;
                break;
            case 'REACTION':
                $reactions = is_array($content['reactions'] ?? null) ? $content['reactions'] : [];
                $emoji = (string) ($incoming->payload['emoji'] ?? '');
                if (($incoming->payload['removed'] ?? false) || $emoji === '') {
                    unset($reactions[$actorKey]);
                } else {
                    $reactions[$actorKey] = mb_substr($emoji, 0, 32);
                }
                $content['reactions'] = $reactions;
                break;
            case 'POLL_VOTE':
                $votes = is_array($content['poll_votes'] ?? null) ? $content['poll_votes'] : [];
                $votes[$actorKey] = [
                    'option_names' => array_values(array_filter(
                        is_array($incoming->payload['option_names'] ?? null) ? $incoming->payload['option_names'] : [],
                        'is_string',
                    )),
                    'option_hashes' => array_values(array_filter(
                        is_array($incoming->payload['option_hashes'] ?? null) ? $incoming->payload['option_hashes'] : [],
                        static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1,
                    )),
                ];
                $content['poll_votes'] = $votes;
                break;
            case 'INTERACTIVE_RESPONSE':
                $content['interactive_response'] = array_filter([
                    'text' => is_string($incoming->payload['text'] ?? null) ? $incoming->payload['text'] : null,
                    'selected_id' => is_string($incoming->payload['selected_id'] ?? null) ? $incoming->payload['selected_id'] : null,
                ], static fn (mixed $value): bool => $value !== null);
                break;
        }
        $metadata['last_action_event_id'] = $incoming->gatewayEventId;
        MessageSemanticContent::assertShape($content, $message->kind);
        $message->metadata = $metadata;
        $message->content_encrypted = $content;
        $message->save();
        if ($action === 'REVOKE') {
            $this->readState->removePendingMessage($canonicalConversation, $message);
        }

        return [(int) $message->conversation_id, (int) $message->id, array_filter([
            'action' => $action,
            'target_message_id' => $targetProviderId,
            'provider_message_id' => $incoming->payload['provider_message_id'] ?? null,
            'emoji' => $action === 'REACTION' ? (string) ($incoming->payload['emoji'] ?? '') : null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /** @return array{0:null,1:null,2:array<string,mixed>} */
    private function ingestHistory(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $messages = $incoming->payload['messages'] ?? null;
        if (! is_array($messages) || count($messages) > 100) {
            throw new RuntimeException('Batch de histórico do gateway inválido.');
        }
        $imported = 0;
        $duplicates = 0;
        foreach ($messages as $index => $payload) {
            if (! is_array($payload)) {
                throw new RuntimeException('Mensagem de histórico inválida.');
            }
            $payload['history'] = true;
            $synthetic = new GatewayEventData(
                gatewayEventId: 'history-'.substr(hash('sha256', $incoming->gatewayEventId.'|'.$index.'|'.($payload['provider_message_id'] ?? '')), 0, 48),
                sessionId: $incoming->sessionId,
                type: GatewayEventType::MessageReceived,
                occurredAt: $incoming->occurredAt,
                payload: $payload,
            );
            $before = CommunicationMessage::query()->withoutGlobalScopes()
                ->where('inbox_id', $inbox->id)
                ->where('provider_message_id', (string) ($payload['provider_message_id'] ?? ''))
                ->exists();
            $this->ingestInbound($synthetic, $inbox, null);
            if ($before) {
                $duplicates++;
            } else {
                $imported++;
            }
        }

        return [null, null, array_filter([
            'batch_id' => $incoming->payload['batch_id'] ?? null,
            'complete' => (bool) ($incoming->payload['complete'] ?? false),
            'imported_count' => $imported,
            'duplicate_count' => $duplicates,
            'rejected_count' => (int) ($incoming->payload['rejected_count'] ?? 0),
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /**
     * @param  array{object_id:string,size_bytes:int,sha256:string}|null  $storedMedia
     * @return array{0:?int,1:?int,2:array<string,mixed>}
     */
    private function ingestMediaRetry(GatewayEventData $incoming, CommunicationInbox $inbox, ?array $storedMedia): array
    {
        $providerId = (string) ($incoming->payload['provider_message_id'] ?? '');
        $status = strtoupper((string) ($incoming->payload['status'] ?? ''));
        if (! in_array($status, ['REQUESTED', 'READY', 'FAILED'], true)) {
            throw new RuntimeException('Estado de media retry inválido.');
        }
        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $providerId)
            ->lockForUpdate()
            ->first();
        if ($message === null) {
            if ($storedMedia !== null) {
                throw new RuntimeException('Media retry não pertence a mensagem desta inbox.');
            }

            return [null, null, ['status' => $status, 'orphan' => true]];
        }
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $currentStatus = strtoupper((string) ($metadata['media_state'] ?? ''));
        $currentGeneration = max(0, (int) ($metadata['media_generation'] ?? 0));
        $incomingGeneration = max(0, (int) ($incoming->payload['generation'] ?? 0));
        $currentAttempt = max(0, (int) ($metadata['media_attempt'] ?? 0));
        $incomingAttempt = max(0, (int) ($incoming->payload['attempt'] ?? 0));
        if ($incomingGeneration < $currentGeneration
            || ($incomingGeneration === $currentGeneration && $incomingAttempt < $currentAttempt)
            || ($currentStatus === 'READY' && $status !== 'READY')) {
            if ($storedMedia !== null) {
                DeleteMediaObjectJob::dispatch($storedMedia['object_id'])->afterCommit();
            }

            return [(int) $message->conversation_id, (int) $message->id, [
                'status' => $currentStatus,
                'generation' => $currentGeneration,
                'attempt' => max(0, (int) ($metadata['media_attempt'] ?? 0)),
                'stale' => true,
            ]];
        }
        $metadata['media_state'] = $status;
        $metadata['media_generation'] = $incomingGeneration;
        $metadata['media_attempt'] = $incomingAttempt;
        if ($status === 'FAILED') {
            $metadata['media_error_code'] = (string) $incoming->payload['error_code'];
        } elseif ($status === 'READY') {
            if ($storedMedia === null) {
                throw new RuntimeException('Media retry READY sem stream confirmado.');
            }
            unset($metadata['media_error_code']);
            $this->upsertAttachment(
                $message,
                $inbox,
                $incoming,
                $storedMedia,
                $incomingGeneration > $currentGeneration,
            );
        }
        $message->forceFill(['metadata' => $metadata])->save();

        return [(int) $message->conversation_id, (int) $message->id, array_filter([
            'status' => $status,
            'generation' => $metadata['media_generation'],
            'attempt' => $metadata['media_attempt'],
            'error_code' => $metadata['media_error_code'] ?? null,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /** @return array{0:?int,1:null,2:array<string,mixed>} */
    private function ingestPresenceSignal(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        [$identity, $conversation] = $this->knownContext($inbox, (string) ($incoming->payload['from'] ?? ''));
        $safe = array_filter([
            'from' => $incoming->payload['from'] ?? null,
            'presence' => $incoming->payload['presence'] ?? null,
            'available' => isset($incoming->payload['available']) ? (bool) $incoming->payload['available'] : null,
            'media' => $incoming->payload['media'] ?? null,
            'last_seen' => $incoming->payload['last_seen'] ?? null,
            'ttl_seconds' => isset($incoming->payload['ttl_seconds']) ? (int) $incoming->payload['ttl_seconds'] : null,
        ], static fn (mixed $value): bool => $value !== null);
        if ($identity !== null && isset($safe['last_seen'])) {
            $identity = CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->whereKey($identity->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lastSeen = Carbon::parse((string) $safe['last_seen']);
            if ($identity->last_seen_at === null || $identity->last_seen_at->isBefore($lastSeen)) {
                $identity->forceFill(['last_seen_at' => $lastSeen])->save();
            }
        }

        return [$conversation?->id, null, $safe];
    }

    /** @return array{0:?int,1:null,2:array<string,mixed>} */
    private function ingestContactProfile(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $user = (string) ($incoming->payload['user'] ?? '');
        [$identity, $conversation] = $this->knownContext($inbox, $user);
        $source = strtoupper(trim((string) ($incoming->payload['source'] ?? '')));
        $fields = $this->profileFields($incoming->payload, $source);
        $clearedFields = array_values(array_filter(
            is_array($incoming->payload['cleared_fields'] ?? null)
                ? $incoming->payload['cleared_fields']
                : [],
            'is_string',
        ));
        if ($identity !== null) {
            $this->identityProfiles->merge(
                $inbox,
                $identity,
                $fields,
                Carbon::parse($incoming->occurredAt),
                $incoming->gatewayEventId,
                $clearedFields,
            );
            $this->profilePictures->schedule($inbox, $identity);
        }

        // The stored event is also broadcast after commit. Keep provider profile data
        // (JIDs, names, about and picture identifiers) inside the profile projection only.
        $safe = array_filter([
            'identity_id' => $identity?->id,
            'source' => $source !== '' ? $source : null,
            'changed_fields' => array_values(array_keys($fields)),
            'cleared_fields' => $clearedFields,
            'from_full_sync' => $incoming->payload['from_full_sync'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return [$conversation?->id, null, $safe];
    }

    /**
     * @param  array<string, mixed>  $safe
     * @return array<string, string>
     */
    private function profileFields(array $safe, string $source): array
    {
        $fields = array_intersect_key($safe, array_flip([
            'address_book_first_name', 'address_book_full_name', 'verified_name',
            'business_name', 'push_name', 'picture_id', 'about',
        ]));
        if (isset($safe['address_book_name']) && is_string($safe['address_book_name'])
            && ! isset($fields['address_book_full_name'])) {
            $fields['address_book_full_name'] = $safe['address_book_name'];
        }

        $display = isset($safe['display_name']) && is_string($safe['display_name'])
            ? $safe['display_name']
            : null;
        if ($display !== null) {
            $fields = match ($source) {
                'ADDRESS_BOOK' => $fields + ['address_book_full_name' => $display],
                'VERIFIED' => $fields + ['verified_name' => $display],
                'BUSINESS' => $fields + ['business_name' => $display],
                'PUSH' => $fields + ['push_name' => $display],
                default => $fields + (
                    isset($fields['address_book_full_name']) || isset($fields['push_name'])
                        ? []
                        : ['push_name' => $display]
                ),
            };
            //  Contact full-name events used display_name without source.
            if ($source === '' && ! isset($safe['business_name']) && ! isset($safe['push_name'])) {
                // Prefer address book when only display_name is present (Contact action).
                $fields['address_book_full_name'] = $fields['address_book_full_name'] ?? $display;
                unset($fields['push_name']);
            }
        }

        return $fields;
    }

    /** @return array{0:?int,1:null,2:array<string,mixed>} */
    private function ingestIdentityChange(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $user = (string) ($incoming->payload['user'] ?? '');
        [, $conversation] = $this->knownContext($inbox, $user);

        return [$conversation?->id, null, [
            'user' => $user,
            'change' => (string) ($incoming->payload['change'] ?? 'IDENTITY_CHANGED'),
        ]];
    }

    /** @return array{0:?CommunicationIdentity,1:?CommunicationConversation} */
    private function knownContext(CommunicationInbox $inbox, string $address): array
    {
        $normalized = $this->normalizer->normalize($address);
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('address_hash', hash('sha256', $normalized))
            ->first();
        if ($identity === null) {
            return [null, null];
        }

        $identityIds = $this->peerCanonicalizer->identityIds($identity);
        $identity = $this->peerCanonicalizer->identity($identity);
        $conversation = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->whereIn('identity_id', $identityIds)
            ->whereNull('merged_into_conversation_id')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        return [$identity, $conversation];
    }

    /** @return array<string,mixed> */
    private function allowlistedStatePayload(GatewayEventData $incoming): array
    {
        $allowed = match ($incoming->type) {
            GatewayEventType::PrivacySettingsChanged => ['settings'],
            GatewayEventType::BlocklistChanged => ['action', 'users'],
            GatewayEventType::ChatStateChanged => ['to', 'action', 'value', 'target_message_id', 'delete_media', 'duration_seconds'],
            GatewayEventType::SyncStatusChanged => ['component', 'status', 'error_code'],
            GatewayEventType::MediaRetryUpdated => ['provider_message_id', 'status', 'error_code'],
            GatewayEventType::GatewayAlert => ['code', 'severity', 'retryable', 'retry_after_seconds'],
            default => [],
        };

        return array_intersect_key($incoming->payload, array_flip($allowed));
    }

    /** @return array{0:?int,1:?int,2:array<string,mixed>} */
    private function ingestReceipt(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $providerId = (string) ($incoming->payload['provider_message_id'] ?? '');
        $status = MessageStatus::tryFrom(strtoupper((string) ($incoming->payload['status'] ?? '')));
        if ($providerId === '' || $status === null) {
            throw new RuntimeException('Receipt do gateway inválido.');
        }
        $message = CommunicationMessage::query()->withoutGlobalScopes()
            ->where('inbox_id', $inbox->id)
            ->where('provider_message_id', $providerId)
            ->lockForUpdate()
            ->first();
        if ($message === null) {
            return [null, null, ['provider_message_id' => $providerId, 'status' => $status->value, 'orphan' => true]];
        }
        $current = $message->status instanceof MessageStatus
            ? $message->status
            : MessageStatus::from((string) $message->status);
        $merged = $current->merge($status);
        if ($merged !== $current) {
            $timestampField = match ($merged) {
                MessageStatus::Accepted => 'accepted_at',
                MessageStatus::Sent => 'sent_at',
                MessageStatus::Delivered => 'delivered_at',
                MessageStatus::Read => 'read_at',
                MessageStatus::Played => 'played_at',
                MessageStatus::Failed => 'failed_at',
                default => null,
            };
            $attributes = ['status' => $merged];
            if ($timestampField !== null) {
                $attributes[$timestampField] = $incoming->occurredAt;
            }
            $message->forceFill($attributes)->save();
        }
        $this->fiscalStatuses->project(
            $message,
            $merged,
            $incoming->occurredAt,
            'WAZYNC',
            $incoming->gatewayEventId,
            PayloadDigest::make($incoming->payload),
        );

        return [(int) $message->conversation_id, (int) $message->id, [
            'provider_message_id' => $providerId,
            'status' => $merged->value,
            'error_code' => isset($incoming->payload['error_code'])
                ? (string) $incoming->payload['error_code']
                : null,
        ]];
    }

    /** @return array{0:null,1:null,2:array<string,mixed>} */
    private function ingestSessionStatus(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $status = is_string($incoming->payload['status'] ?? null)
            ? InboxStatus::tryFrom($incoming->payload['status'])
            : null;
        if ($status === null) {
            throw new RuntimeException('Status de sessão inválido.');
        }
        $inbox->forceFill([
            'status' => $status,
            'connected_at' => $status === InboxStatus::Connected ? $incoming->occurredAt : $inbox->connected_at,
            'last_seen_at' => $incoming->occurredAt,
            'revoked_at' => $status === InboxStatus::Connected ? null : $inbox->revoked_at,
            'lock_version' => (int) $inbox->lock_version + 1,
        ])->save();
        if ($status === InboxStatus::Connected) {
            $this->pairing->forget((int) $inbox->id);
        } elseif ($status === InboxStatus::Disconnected
            && is_string($incoming->payload['reason_code'] ?? null)
            && trim((string) $incoming->payload['reason_code']) !== '') {
            $this->pairing->put((int) $inbox->id, [
                'event' => 'error',
                'error_code' => strtoupper(trim((string) $incoming->payload['reason_code'])),
                'expires_at' => now()->addMinutes(2)->toIso8601String(),
            ]);
        }

        return [null, null, ['status' => $status->value]];
    }

    /** @return array{0:null,1:null,2:array<string,mixed>} */
    private function ingestPairing(GatewayEventData $incoming, CommunicationInbox $inbox): array
    {
        $event = strtoupper((string) ($incoming->payload['event'] ?? ''));
        if ($event === '') {
            throw new RuntimeException('Evento de pairing inválido.');
        }
        if (in_array($event, ['CODE', 'QR', 'QR_AVAILABLE', 'PASSKEY_REQUIRED', 'PASSKEY_CONFIRMATION_REQUIRED'], true)) {
            $this->pairing->put((int) $inbox->id, $incoming->payload);
            $inbox->forceFill(['status' => InboxStatus::Connecting, 'lock_version' => (int) $inbox->lock_version + 1])->save();
        } elseif (in_array($event, ['SUCCESS', 'PAIRED'], true)) {
            $this->pairing->forget((int) $inbox->id);
            $inbox->forceFill([
                'status' => InboxStatus::Connected,
                'connected_at' => $incoming->occurredAt,
                'last_seen_at' => $incoming->occurredAt,
                'lock_version' => (int) $inbox->lock_version + 1,
            ])->save();
        } else {
            $this->pairing->put((int) $inbox->id, [
                'event' => strtolower((string) $incoming->payload['event']),
                'error_code' => isset($incoming->payload['error_code'])
                    ? strtoupper((string) $incoming->payload['error_code'])
                    : 'PAIRING_FAILED',
                'expires_at' => $incoming->payload['expires_at'] ?? null,
            ]);
            $inbox->forceFill(['status' => InboxStatus::Disconnected, 'lock_version' => (int) $inbox->lock_version + 1])->save();
        }

        return [null, null, [
            'event' => $event,
            'expires_at' => $incoming->payload['expires_at'] ?? null,
            'error_code' => $incoming->payload['error_code'] ?? null,
        ]];
    }

    /**
     * @param  array<string,mixed>  $content
     * @param  array<string,mixed>  $incomingMetadata
     * @param  array{object_id:string,size_bytes:int,sha256:string}|null  $storedMedia
     */
    private function enrichExistingMessage(
        CommunicationMessage $message,
        CommunicationConversation $conversation,
        MessageDirection $direction,
        MessageKind $kind,
        string $providerType,
        string $body,
        array $content,
        ?int $replyToMessageId,
        array $incomingMetadata,
        ?array $storedMedia,
        GatewayEventData $incoming,
        CommunicationInbox $inbox,
        \DateTimeInterface $occurredAt,
    ): bool {
        $existingDirection = $message->direction instanceof MessageDirection
            ? $message->direction
            : MessageDirection::from((string) $message->direction);
        if ($existingDirection !== $direction) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com outra direção.');
        }

        if ($message->purged_at !== null || $message->revoked_at !== null || $message->quarantined_at !== null) {
            if ($storedMedia !== null) {
                DeleteMediaObjectJob::dispatch($storedMedia['object_id'])->afterCommit();
            }

            return false;
        }

        $existingKind = $message->kind instanceof MessageKind
            ? $message->kind
            : MessageKind::from((string) $message->kind);
        $promotingUnsupported = $existingKind === MessageKind::Unsupported
            && $kind !== MessageKind::Unsupported;
        if ($existingKind !== $kind && ! $promotingUnsupported) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com outro kind.');
        }
        $existingProviderType = trim((string) $message->provider_type);
        if ($existingProviderType !== ''
            && ! hash_equals($existingProviderType, $providerType)
            && ! $promotingUnsupported) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com outro tipo semântico.');
        }

        $attributes = [];
        if ($promotingUnsupported) {
            $attributes['kind'] = $kind;
            $attributes['provider_type'] = $providerType;
        } elseif ($existingProviderType === '') {
            $attributes['provider_type'] = $providerType;
        }

        $existingMetadata = is_array($message->metadata) ? $message->metadata : [];
        $wasEdited = trim((string) ($existingMetadata['edited_at'] ?? '')) !== '';
        $existingBody = trim((string) $message->body_encrypted);
        if (! $wasEdited && $existingBody !== '' && $body !== '' && ! hash_equals($existingBody, $body)) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com outro corpo.');
        }
        if (! $wasEdited && $existingBody === '' && $body !== '') {
            $attributes['body_encrypted'] = $body;
        }

        $existingContent = is_array($message->content_encrypted) ? $message->content_encrypted : [];
        $mergedContent = $this->mergeSemanticContent($existingContent, $content, $wasEdited);
        if ($mergedContent !== $existingContent) {
            $attributes['content_encrypted'] = $mergedContent;
        }

        if ($message->reply_to_message_id !== null
            && $replyToMessageId !== null
            && (int) $message->reply_to_message_id !== $replyToMessageId) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com outra resposta.');
        }
        if ($message->reply_to_message_id === null && $replyToMessageId !== null) {
            $attributes['reply_to_message_id'] = $replyToMessageId;
        }

        if ($storedMedia !== null && $this->hasPurgedAttachment($message)) {
            DeleteMediaObjectJob::dispatch($storedMedia['object_id'])->afterCommit();
            $storedMedia = null;
            unset($incomingMetadata['media_error_code']);
            $incomingMetadata['media_state'] = 'UNAVAILABLE';
        }

        $hasExistingMedia = CommunicationAttachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('message_id', $message->id)
            ->whereNull('purged_at')
            ->exists();
        $metadata = $this->mergeAvailabilityMetadata(
            $existingMetadata,
            $incomingMetadata,
            $storedMedia !== null,
            $hasExistingMedia,
        );
        if ($metadata !== (is_array($message->metadata) ? $message->metadata : [])) {
            $attributes['metadata'] = $metadata;
        }

        $attachmentChanged = $storedMedia !== null
            ? $this->upsertAttachment($message, $inbox, $incoming, $storedMedia, false)
            : false;
        $changed = $attributes !== [] || $attachmentChanged;
        if ($changed) {
            $effectiveKind = $promotingUnsupported ? $kind : $existingKind;
            $effectiveProviderType = (string) ($attributes['provider_type'] ?? $message->provider_type);
            $effectiveBody = (string) ($attributes['body_encrypted'] ?? $message->body_encrypted ?? '');
            $effectiveContent = $attributes['content_encrypted'] ?? $existingContent;
            $attachmentSha = $storedMedia['sha256'] ?? CommunicationAttachment::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $message->tenant_id)
                ->where('message_id', $message->id)
                ->whereNull('purged_at')
                ->orderBy('id')
                ->value('sha256') ?? '';
            $attributes['content_digest'] = hash('sha256', implode('|', [
                $effectiveKind->value,
                $effectiveProviderType,
                $effectiveBody,
                PayloadDigest::make($effectiveContent),
                $attachmentSha,
            ]));
            $message->forceFill($attributes)->save();
        }

        $lockedConversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->whereKey($conversation->id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($lockedConversation->last_message_at === null
            || $lockedConversation->last_message_at->isBefore($message->occurred_at)) {
            $lockedConversation->forceFill(['last_message_at' => $message->occurred_at])->save();
        }

        return $changed;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming */
    private function mergeSemanticContent(array $existing, array $incoming, bool $preserveDivergent = false): array
    {
        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $current = $merged[$key] ?? null;
            if ($current === null || $current === '' || $current === []) {
                $merged[$key] = $value;

                continue;
            }
            if (PayloadDigest::make(['value' => $current])
                !== PayloadDigest::make(['value' => $value])) {
                if ($preserveDivergent) {
                    continue;
                }
                throw new GatewayEventConflictException('Provider message ID reutilizado com conteúdo divergente.');
            }
        }

        return $merged;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $incoming */
    private function mergeAvailabilityMetadata(
        array $existing,
        array $incoming,
        bool $hasIncomingMedia,
        bool $hasExistingMedia,
    ): array {
        $merged = $existing;
        foreach (['history', 'ephemeral', 'view_once'] as $flag) {
            if (($incoming[$flag] ?? false) === true) {
                $merged[$flag] = true;
            }
        }

        $currentState = strtoupper((string) ($merged['media_state'] ?? ''));
        $incomingState = $hasIncomingMedia ? 'READY' : strtoupper((string) ($incoming['media_state'] ?? ''));
        if ($incomingState !== '') {
            $merged['media_state'] = match (true) {
                $hasIncomingMedia => 'READY',
                $currentState === 'READY' && $hasExistingMedia => 'READY',
                $incomingState === 'UNAVAILABLE' => 'UNAVAILABLE',
                $currentState === 'REQUESTED' && $incomingState === 'RETRY_AVAILABLE' => 'REQUESTED',
                default => $incomingState,
            };
        }
        if (($merged['media_state'] ?? null) === 'READY') {
            if (! $hasIncomingMedia && ! $hasExistingMedia) {
                $merged['media_state'] = 'UNAVAILABLE';
            }
            unset($merged['media_error_code']);
        } elseif (is_string($incoming['media_error_code'] ?? null)) {
            $merged['media_error_code'] = $incoming['media_error_code'];
        }

        return $merged;
    }

    /**
     * @param  array{object_id:string,size_bytes:int,sha256:string}  $storedMedia
     */
    private function upsertAttachment(
        CommunicationMessage $message,
        CommunicationInbox $inbox,
        GatewayEventData $incoming,
        array $storedMedia,
        bool $allowReplacement,
    ): bool {
        $attachment = CommunicationAttachment::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('message_id', $message->id)
            ->whereNull('purged_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $attributes = [
            'tenant_id' => $inbox->tenant_id,
            'message_id' => $message->id,
            'object_id' => $storedMedia['object_id'],
            'original_name_encrypted' => $this->safeFilename(
                (string) ($incoming->payload['filename'] ?? ''),
                $message->kind instanceof MessageKind
                    ? $message->kind
                    : MessageKind::from((string) $message->kind),
            ),
            'mime_type' => $this->safeMime((string) ($incoming->payload['mime_type'] ?? 'application/octet-stream')),
            'size_bytes' => $storedMedia['size_bytes'],
            'sha256' => $storedMedia['sha256'],
            'storage_context' => [
                'tenant_id' => (int) $inbox->tenant_id,
                'inbox_id' => (int) $inbox->id,
                'gateway_event_id' => $incoming->gatewayEventId,
                'sha256' => $storedMedia['sha256'],
            ],
            'purged_at' => null,
        ];
        if ($attachment === null) {
            CommunicationAttachment::query()->withoutGlobalScopes()->create($attributes);

            return true;
        }
        if (hash_equals((string) $attachment->sha256, $storedMedia['sha256'])
            && (int) $attachment->size_bytes === $storedMedia['size_bytes']) {
            if (! hash_equals((string) $attachment->object_id, $storedMedia['object_id'])) {
                DeleteMediaObjectJob::dispatch($storedMedia['object_id'])->afterCommit();
            }

            return false;
        }
        if (! $allowReplacement) {
            throw new GatewayEventConflictException('Provider message ID reutilizado com mídia divergente.');
        }

        $previousObjectId = (string) $attachment->object_id;
        $attachment->forceFill($attributes)->save();
        if ($previousObjectId !== '' && ! hash_equals($previousObjectId, $storedMedia['object_id'])) {
            DeleteMediaObjectJob::dispatch($previousObjectId)->afterCommit();
        }

        return true;
    }

    private function hasPurgedAttachment(CommunicationMessage $message): bool
    {
        return CommunicationAttachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('message_id', $message->id)
            ->whereNotNull('purged_at')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    private function safeMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) ? $mime : 'application/octet-stream';
    }

    private function lockAdvisory(string $scope, string $key): void
    {
        $bytes = substr(hash('sha256', $scope.'|'.$key, true), 0, 8);
        /** @var array{scope:int,key:int} $parts */
        $parts = unpack('Nscope/Nkey', $bytes);
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [
            $this->signedInt32($parts['scope']),
            $this->signedInt32($parts['key']),
        ]);
    }

    private function signedInt32(int $value): int
    {
        return $value > 0x7FFFFFFF ? $value - 0x100000000 : $value;
    }

    private function safeFilename(string $filename, MessageKind $kind): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
        if ($filename === '') {
            $filename = match ($kind) {
                MessageKind::Image => 'imagem',
                MessageKind::Audio => 'audio',
                MessageKind::Video => 'video',
                MessageKind::Sticker => 'sticker.webp',
                default => 'anexo',
            };
        }

        return mb_substr($filename, 0, 255);
    }
}
