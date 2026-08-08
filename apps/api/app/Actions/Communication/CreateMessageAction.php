<?php

namespace App\Actions\Communication;

use App\Contracts\CommunicationOutboundMessageWriter;
use App\DTO\Communication\MessageBatchItemContext;
use App\DTO\Communication\MessageCreationData;
use App\DTO\Communication\MessageCreationResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Enums\Communication\OutboundCapabilityUnavailableReason;
use App\Exceptions\CommunicationConversationApiException;
use App\Exceptions\UnsupportedMessageKindException;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationStickerObservation;
use App\Services\Communication\Availability;
use App\Services\Communication\Catalog\OutboundCapabilityEvaluator;
use App\Services\Communication\Conversation\MessageIdempotency;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Flows\FlowRunControlService;
use App\Services\Communication\Media\MediaStore;
use App\Services\Communication\Media\OutboundMediaValidator;
use App\Services\Communication\Outbox\OutboxService;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Writer compartilhado de outbound: staging de blob, TX, evento/outbox e cleanup. */
final readonly class CreateMessageAction implements CommunicationOutboundMessageWriter
{
    private const PROVIDER_MESSAGE_CONSTRAINT = 'communication_messages_inbox_id_provider_message_id_unique';

    public function __construct(
        private CurrentTenant $currentTenant,
        private Availability $availability,
        private OutboundCapabilityEvaluator $capabilities,
        private ConversationCanonicalizer $canonicalizer,
        private MessageIdempotency $idempotency,
        private OutboxService $outbox,
        private EventRecorder $events,
        private MediaStore $media,
        private OutboundMediaValidator $mediaValidator,
        private FlowRunControlService $flowRuns,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        MessageCreationData $data,
        ?MessageBatchItemContext $batchItem = null,
    ): MessageCreationResult {
        try {
            return $this->createMessage($conversation, $data, $batchItem);
        } finally {
            $this->forgetLibraryStickerTemp($data);
        }
    }

    private function createMessage(
        CommunicationConversation $conversation,
        MessageCreationData $data,
        ?MessageBatchItemContext $batchItem = null,
    ): MessageCreationResult {
        $conversation = $this->canonicalizer->conversation($conversation);
        $conversation->loadMissing(['inbox', 'identity']);
        if ($data->internalNote && $data->upload !== null) {
            throw CommunicationConversationApiException::internalNoteAttachment();
        }
        if ($data->internalNote && $data->receiptMessageId !== null) {
            throw ValidationException::withMessages([
                'receipt_message_id' => 'Nota interna não envia confirmação de leitura ao WhatsApp.',
            ]);
        }

        if ($data->requestedKind === MessageKind::Unsupported) {
            throw CommunicationConversationApiException::unsupportedMessageKind();
        }
        $validatedMedia = $data->upload !== null
            ? $this->mediaValidator->inspect($data->upload, $data->requestedKind)
            : null;
        $mime = $validatedMedia?->mime;
        $this->assertPayloadFamiliesAreCompatible($data);
        $kind = $this->resolveMessageKind(
            $data->internalNote,
            $mime,
            $data->requestedKind,
            $data->richPayload,
        );
        if ($data->ptt && ($data->upload === null || $kind !== MessageKind::Audio)) {
            throw ValidationException::withMessages([
                'ptt' => 'PTT exige um arquivo de áudio.',
            ]);
        }
        $this->assertNewFamilyIsEnabled($data, $kind);

        $replyTo = $data->replyToMessageId !== null
            ? CommunicationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->findOrFail($data->replyToMessageId)
            : null;
        $replyProviderId = $this->replyProviderId(
            $data->internalNote,
            $replyTo,
        );
        $receiptTarget = $this->receiptTarget($conversation, $data->receiptMessageId);
        $providerId = $data->internalNote
            ? null
            : $this->idempotency->providerId($data->idempotencyKey, $data->outboundInitiation);
        $uploadDigest = $validatedMedia?->sha256;
        $contentDigestParts = [
            $kind->value,
            $data->body,
            $uploadDigest ?? '',
            $replyProviderId ?? '',
            $data->ptt ? 'ptt' : 'media',
            $data->gif ? 'gif' : 'not-gif',
            $data->ptv ? 'ptv' : 'not-ptv',
            $data->viewOnce ? 'view-once' : 'not-view-once',
            json_encode($data->richPayload, JSON_THROW_ON_ERROR),
            $receiptTarget?->id ?? '',
        ];
        if ($data->outboundInitiation) {
            array_unshift($contentDigestParts, $this->idempotency->namespace(true));
        }
        $contentDigest = hash('sha256', implode('|', $contentDigestParts));
        $previousContentDigest = $this->previousContentDigest(
            $kind,
            $data,
            $uploadDigest,
            $replyProviderId,
            $receiptTarget,
        );

        if ($providerId !== null) {
            $existing = $this->existingMessage(
                (int) $conversation->inbox_id,
                $providerId,
            );
            if ($existing !== null) {
                return DB::transaction(function () use (
                    $conversation,
                    $providerId,
                    $contentDigest,
                    $previousContentDigest,
                ): MessageCreationResult {
                    $canonical = $this->canonicalizer->lockConversation($conversation);
                    $locked = CommunicationMessage::query()
                        ->where('inbox_id', $canonical->inbox_id)
                        ->where('provider_message_id', $providerId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    return $this->idempotentResult(
                        $locked,
                        $contentDigest,
                        (int) $canonical->id,
                        $previousContentDigest,
                    );
                });
            }
        }

        $this->availability->assertEnabled($conversation->inbox, ! $data->internalNote);

        $stored = null;
        $storageContext = null;
        try {
            if ($data->upload !== null) {
                $stream = fopen($data->upload->path, 'rb');
                if (! is_resource($stream)) {
                    throw CommunicationConversationApiException::invalidAttachment();
                }

                $storageContext = [
                    'tenant_id' => (int) $conversation->tenant_id,
                    'inbox_id' => (int) $conversation->inbox_id,
                    'upload_id' => (string) Str::uuid(),
                ];
                try {
                    $stored = $this->media->putStream($stream, $storageContext);
                } finally {
                    fclose($stream);
                }
                if ($uploadDigest === null || ! hash_equals($uploadDigest, $stored['sha256'])) {
                    $this->media->delete($stored['object_id']);
                    throw CommunicationConversationApiException::attachmentIntegrityFailure();
                }
            }

            $message = DB::transaction(function () use (
                $conversation,
                $data,
                $providerId,
                $contentDigest,
                $kind,
                $mime,
                $replyTo,
                $replyProviderId,
                $receiptTarget,
                $stored,
                $storageContext,
                $batchItem,
            ): CommunicationMessage {
                $lockedConversation = $this->canonicalizer
                    ->lockConversation($conversation)
                    ->load(['inbox', 'identity']);
                if ($lockedConversation->purged_at !== null) {
                    throw CommunicationConversationApiException::purged();
                }
                $message = CommunicationMessage::query()->create([
                    'tenant_id' => $lockedConversation->tenant_id,
                    'inbox_id' => $lockedConversation->inbox_id,
                    'conversation_id' => $lockedConversation->id,
                    'identity_id' => $lockedConversation->identity_id,
                    'reply_to_message_id' => $replyTo?->id,
                    'author_membership_id' => $this->currentTenant->realMembership()?->id,
                    'message_batch_id' => $batchItem?->batchId,
                    'batch_position' => $batchItem?->position,
                    'direction' => $data->internalNote
                        ? MessageDirection::Internal
                        : MessageDirection::Outbound,
                    'kind' => $kind,
                    'provider_type' => $this->outboundProviderType($kind, $data->richPayload, $data->ptv),
                    'source' => MessageSource::Human,
                    'status' => $data->internalNote ? MessageStatus::Sent : MessageStatus::Queued,
                    'body_encrypted' => $data->body !== '' ? $data->body : null,
                    'content_encrypted' => $this->hasSemanticContent($data)
                        ? $this->outboundContent($data)
                        : null,
                    'metadata' => array_filter([
                        'receipt_message_id' => $receiptTarget?->id,
                        'outbound_initiation' => $data->outboundInitiation ?: null,
                        'view_once' => $data->viewOnce ?: null,
                        'batch_id' => $batchItem?->gatewayBatchId,
                        'batch_position' => $batchItem?->position,
                        'batch_size' => $batchItem?->size,
                    ], static fn (mixed $value): bool => $value !== null),
                    'provider_message_id' => $providerId,
                    'content_digest' => $contentDigest,
                    'occurred_at' => now(),
                    'sent_at' => $data->internalNote ? now() : null,
                ]);
                $attachment = $this->createAttachment(
                    $lockedConversation,
                    $message,
                    $data,
                    $mime,
                    $stored,
                    $storageContext,
                );

                $lockedConversation->forceFill([
                    'last_message_at' => $message->occurred_at,
                    'lock_version' => (int) $lockedConversation->lock_version + 1,
                ])->save();

                if (! $data->internalNote && $batchItem === null) {
                    $this->enqueueOutbound(
                        $lockedConversation,
                        $message,
                        $kind,
                        $data,
                        $replyTo,
                        $replyProviderId,
                        $attachment,
                    );
                    $this->flowRuns->handoffActiveForConversation(
                        (int) $lockedConversation->id,
                        $this->currentTenant->realMembership(),
                        'human_outbound',
                    );
                }

                $this->events->record(
                    (int) $lockedConversation->tenant_id,
                    $data->internalNote ? 'INTERNAL_NOTE_CREATED' : 'MESSAGE_QUEUED',
                    [
                        'message_id' => (int) $message->id,
                        'direction' => $message->direction->value,
                        'kind' => $message->kind->value,
                        'has_media' => $attachment !== null,
                    ],
                    inboxId: (int) $lockedConversation->inbox_id,
                    conversationId: (int) $lockedConversation->id,
                    messageId: (int) $message->id,
                    actorMembershipId: $this->currentTenant->realMembership()?->id,
                );

                return $message;
            });

            if ($data->libraryStickerId !== null) {
                $observation = CommunicationStickerObservation::query()
                    ->where('inbox_id', $conversation->inbox_id)
                    ->where('public_id', $data->libraryStickerId)
                    ->with('content')
                    ->first();
                $content = $observation?->content;
                if ($content !== null && ! $content->retention_protected) {
                    $content->forceFill(['retention_protected' => true])->save();
                }
            }
        } catch (Throwable $error) {
            if (is_array($stored)) {
                $this->media->delete($stored['object_id']);
            }

            if ($providerId !== null
                && $error instanceof QueryException
                && $this->isProviderMessageConflict($error)) {
                $existing = $this->existingMessage(
                    (int) $conversation->inbox_id,
                    $providerId,
                );
                if ($existing !== null) {
                    return $this->idempotentResult(
                        $existing,
                        $contentDigest,
                        (int) $conversation->id,
                        $previousContentDigest,
                    );
                }
            }

            throw $error;
        }

        return new MessageCreationResult(
            message: $message->load('attachments'),
            httpStatus: $data->internalNote ? 201 : 202,
        );
    }

    private function forgetLibraryStickerTemp(MessageCreationData $data): void
    {
        if (is_string($data->libraryStickerTempPath) && $data->libraryStickerTempPath !== '') {
            @unlink($data->libraryStickerTempPath);
        }
    }

    private function assertPayloadFamiliesAreCompatible(
        MessageCreationData $data,
    ): void {
        if ($data->internalNote && ($data->upload !== null || $data->richPayload !== [])) {
            throw ValidationException::withMessages([
                'internal_note' => 'Nota interna aceita somente texto, sem mídia ou conteúdo rico.',
            ]);
        }

        $familyPayloads = array_intersect_key(
            $data->richPayload,
            array_flip(['location', 'contact', 'contacts', 'poll', 'event', 'interactive']),
        );
        if (count($familyPayloads) > 1
            || ($data->upload !== null && $data->richPayload !== [])
            || (isset($data->richPayload['link_preview']) && $familyPayloads !== [])
            || ($data->body !== '' && $familyPayloads !== [])) {
            throw ValidationException::withMessages([
                'kind' => 'Envie exatamente um DTO de família por mensagem.',
            ]);
        }

        if (($data->gif || $data->ptv || $data->viewOnce) && $data->upload === null) {
            throw ValidationException::withMessages([
                'kind' => 'Variantes de mídia exigem um arquivo compatível.',
            ]);
        }
        if (count(array_filter([$data->gif, $data->ptv, $data->viewOnce])) > 1) {
            throw ValidationException::withMessages([
                'kind' => 'GIF, PTV e view-once são variantes incompatíveis entre si.',
            ]);
        }
    }

    private function assertNewFamilyIsEnabled(MessageCreationData $data, MessageKind $kind): void
    {
        $feature = match (true) {
            isset($data->richPayload['contacts']) => 'contacts_array',
            isset($data->richPayload['event']) => 'event',
            $data->gif => 'gif',
            $data->ptv => 'ptv',
            $data->viewOnce => 'view_once',
            default => null,
        };
        if ($feature !== null) {
            $this->capabilities->assertFeatureEnabled($feature, match ($feature) {
                'contacts_array' => OutboundCapabilityUnavailableReason::ContactsArrayBuilderUnimplemented,
                'gif' => OutboundCapabilityUnavailableReason::GifPlaybackBuilderUnimplemented,
                'ptv' => OutboundCapabilityUnavailableReason::PtvBuilderUnimplemented,
                'event' => OutboundCapabilityUnavailableReason::EventBuilderUnimplemented,
                'view_once' => OutboundCapabilityUnavailableReason::ViewOnceBuilderUnimplemented,
            });
        }
        if ($data->gif && $kind !== MessageKind::Video) {
            throw ValidationException::withMessages(['gif' => 'GIF exige um arquivo de vídeo.']);
        }
        if ($data->ptv && $kind !== MessageKind::Video) {
            throw ValidationException::withMessages(['ptv' => 'PTV exige um arquivo de vídeo.']);
        }
        if ($data->viewOnce && ! in_array($kind, [MessageKind::Image, MessageKind::Video], true)) {
            throw ValidationException::withMessages(['view_once' => 'View-once exige imagem ou vídeo.']);
        }
    }

    private function replyProviderId(
        bool $internalNote,
        ?CommunicationMessage $replyTo,
    ): ?string {
        if ($internalNote || $replyTo === null) {
            return null;
        }

        $providerId = trim((string) $replyTo->provider_message_id);
        if ($providerId === '') {
            throw ValidationException::withMessages([
                'reply_to_message_id' => 'A mensagem citada ainda não possui identificador remoto.',
            ]);
        }

        return $providerId;
    }

    private function receiptTarget(
        CommunicationConversation $conversation,
        ?int $receiptMessageId,
    ): ?CommunicationMessage {
        if ($receiptMessageId === null) {
            return null;
        }

        $message = CommunicationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereKey($receiptMessageId)
            ->where('direction', MessageDirection::Inbound)
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->first();
        if ($message === null || trim((string) $message->provider_message_id) === '') {
            throw ValidationException::withMessages([
                'receipt_message_id' => 'A confirmação exige uma mensagem inbound disponível desta conversa.',
            ]);
        }

        return $message;
    }

    private function existingMessage(
        int $inboxId,
        string $providerId,
    ): ?CommunicationMessage {
        return CommunicationMessage::query()
            ->where('inbox_id', $inboxId)
            ->where('provider_message_id', $providerId)
            ->first();
    }

    private function idempotentResult(
        CommunicationMessage $existing,
        string $contentDigest,
        ?int $conversationId = null,
        ?string $previousContentDigest = null,
    ): MessageCreationResult {
        $digestMatches = hash_equals((string) $existing->content_digest, $contentDigest)
            || ($previousContentDigest !== null
                && hash_equals((string) $existing->content_digest, $previousContentDigest));
        if (! $digestMatches
            || ($conversationId !== null && (int) $existing->conversation_id !== $conversationId)) {
            throw CommunicationConversationApiException::idempotencyConflict();
        }

        return new MessageCreationResult(
            message: $existing->load('attachments'),
            httpStatus: 200,
        );
    }

    private function previousContentDigest(
        MessageKind $kind,
        MessageCreationData $data,
        ?string $uploadDigest,
        ?string $replyProviderId,
        ?CommunicationMessage $receiptTarget,
    ): string {
        $parts = [
            $kind->value,
            $data->body,
            $uploadDigest ?? '',
            $replyProviderId ?? '',
            $data->ptt ? 'ptt' : 'media',
            json_encode($data->richPayload, JSON_THROW_ON_ERROR),
            $receiptTarget?->id ?? '',
        ];
        if ($data->outboundInitiation) {
            array_unshift($parts, $this->idempotency->namespace(true));
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @param  array<string, mixed>|null  $storageContext
     */
    private function createAttachment(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        MessageCreationData $data,
        ?string $mime,
        ?array $stored,
        ?array $storageContext,
    ): ?CommunicationAttachment {
        if ($data->upload === null || $stored === null || $storageContext === null) {
            return null;
        }

        return CommunicationAttachment::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'message_id' => $message->id,
            'object_id' => $stored['object_id'],
            'original_name_encrypted' => $this->safeFilename($data->upload->originalName),
            'mime_type' => $mime ?? 'application/octet-stream',
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
            'storage_context' => $storageContext,
        ]);
    }

    private function enqueueOutbound(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        MessageKind $kind,
        MessageCreationData $data,
        ?CommunicationMessage $replyTo,
        ?string $replyProviderId,
        ?CommunicationAttachment $attachment,
    ): void {
        $payload = [
            'to' => $conversation->identity->address_encrypted,
            'kind' => $kind->value,
        ];
        if ($kind === MessageKind::Text) {
            $payload['text'] = $data->body;
            if (isset($data->richPayload['link_preview'])) {
                $payload['link_preview'] = $data->richPayload['link_preview'];
            }
        } elseif ($data->body !== '' && in_array(
            $kind,
            [MessageKind::Image, MessageKind::Video, MessageKind::Document],
            true,
        )) {
            $payload['caption'] = $data->body;
        }
        foreach (['location', 'contact', 'contacts', 'poll', 'event', 'interactive'] as $field) {
            if (isset($data->richPayload[$field])) {
                $payload[$field] = $data->richPayload[$field];
            }
        }
        if ($replyTo !== null && $replyProviderId !== null) {
            $payload['reply_to'] = array_filter([
                'message_id' => $replyProviderId,
                'sender' => $replyTo->direction === MessageDirection::Inbound
                    ? (string) $conversation->identity->address_encrypted
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }
        if ($attachment !== null) {
            $payload['media'] = [
                'attachment_id' => (int) $attachment->id,
                'mime_type' => $attachment->mime_type,
                'filename' => (string) $attachment->original_name_encrypted,
                'size_bytes' => (int) $attachment->size_bytes,
                'sha256' => $attachment->sha256,
                'ptt' => $data->ptt,
                'gif' => $data->gif,
                'ptv' => $data->ptv,
                'view_once' => $data->viewOnce,
            ];
        }

        $this->outbox->enqueue(
            $conversation->inbox,
            GatewayCommandType::SendMessage,
            $payload,
            $message,
        );
    }

    /**
     * @param  array<string, mixed>  $richPayload
     */
    private function resolveMessageKind(
        bool $internal,
        ?string $mime,
        ?MessageKind $requested,
        array $richPayload,
    ): MessageKind {
        if ($internal) {
            if ($requested !== null && $requested !== MessageKind::Text) {
                throw ValidationException::withMessages([
                    'kind' => 'Nota interna aceita somente texto.',
                ]);
            }

            return MessageKind::Note;
        }
        if ($mime === null) {
            $expectedRich = match (true) {
                isset($richPayload['location']) => MessageKind::Location,
                isset($richPayload['contact']) => MessageKind::Contact,
                isset($richPayload['contacts']) => MessageKind::Contact,
                isset($richPayload['poll']) => MessageKind::Poll,
                isset($richPayload['event']) => MessageKind::Event,
                isset($richPayload['interactive']) => MessageKind::Interactive,
                default => MessageKind::Text,
            };
            if ($requested !== null && $requested !== $expectedRich) {
                throw ValidationException::withMessages([
                    'kind' => 'O tipo informado não corresponde ao DTO enviado.',
                ]);
            }

            return $expectedRich;
        }

        $kind = $requested ?? match (true) {
            str_starts_with($mime, 'image/') => MessageKind::Image,
            str_starts_with($mime, 'audio/') => MessageKind::Audio,
            str_starts_with($mime, 'video/') => MessageKind::Video,
            default => MessageKind::Document,
        };
        $matches = match ($kind) {
            MessageKind::Image => str_starts_with($mime, 'image/'),
            MessageKind::Audio => str_starts_with($mime, 'audio/'),
            MessageKind::Video => str_starts_with($mime, 'video/'),
            MessageKind::Document => $mime !== '',
            MessageKind::Sticker => $mime === 'image/webp',
            default => false,
        };
        if (! $matches) {
            throw ValidationException::withMessages([
                'kind' => "O tipo {$kind->value} não corresponde ao MIME {$mime}.",
            ]);
        }

        return $kind;
    }

    /** @param array<string, mixed> $richPayload */
    private function outboundProviderType(
        MessageKind $kind,
        array $richPayload,
        bool $ptv,
    ): string {
        return match ($kind) {
            MessageKind::Text => isset($richPayload['link_preview'])
                ? 'extendedTextMessage'
                : 'conversation',
            MessageKind::Image => 'imageMessage',
            MessageKind::Audio => 'audioMessage',
            MessageKind::Video => $ptv ? 'ptvMessage' : 'videoMessage',
            MessageKind::Document => 'documentMessage',
            MessageKind::Sticker => 'stickerMessage',
            MessageKind::Location => 'locationMessage',
            MessageKind::Contact => count($richPayload['contacts'] ?? []) > 1
                ? 'contactsArrayMessage'
                : 'contactMessage',
            MessageKind::Poll => 'pollCreationMessageV3',
            MessageKind::Event => 'eventMessage',
            MessageKind::Interactive => 'interactiveMessage',
            MessageKind::Note => 'internalNote',
            MessageKind::Unsupported => throw new UnsupportedMessageKindException,
        };
    }

    /**
     * @param  array<string, mixed>  $richPayload
     * @return array<string, mixed>
     */
    private function outboundContent(MessageCreationData $data): array
    {
        $content = $data->richPayload;
        if (isset($content['contact'])) {
            $content['contacts'] = [$content['contact']];
            unset($content['contact']);
        }
        if ($data->ptt) {
            $content['ptt'] = true;
        }
        if ($data->gif) {
            $content['gif'] = true;
        }
        if ($data->ptv) {
            $content['variants'] = ['ptv'];
        }

        return $content;
    }

    private function hasSemanticContent(MessageCreationData $data): bool
    {
        return $data->richPayload !== [] || $data->ptt || $data->gif || $data->ptv;
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';

        return mb_substr($filename !== '' ? $filename : 'anexo', 0, 255);
    }

    private function isProviderMessageConflict(QueryException $error): bool
    {
        return (string) $error->getCode() === '23505'
            && str_contains(
                (string) ($error->errorInfo[2] ?? ''),
                self::PROVIDER_MESSAGE_CONSTRAINT,
            );
    }
}
