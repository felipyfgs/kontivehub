<?php

namespace App\Services\Communication;

use App\DTO\Communication\MessageAvailabilityData;
use App\Enums\Communication\MessageAvailabilityState;
use App\Enums\Communication\MessageKind;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationMessage;
use App\Services\Communication\Media\MediaStore;
use RuntimeException;

final class MessageAvailability
{
    /** @var list<string> */
    private const MEDIA_KINDS = [
        'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER',
    ];

    /** @var list<string> */
    private const TERMINAL_MEDIA_ERRORS = [
        'MEDIA_RETRY_STATE_MISSING',
        'MEDIA_RETRY_INVALID_REQUEST',
        'MEDIA_RETRY_DESCRIPTOR_EXPIRED',
        'MEDIA_RETRY_DESCRIPTOR_INVALID',
        'MEDIA_RETRY_NOT_AVAILABLE',
    ];

    public function __construct(
        private readonly MediaStore $media,
    ) {}

    public function forMessage(
        CommunicationMessage $message,
        ?bool $hasAvailableAttachment = null,
    ): MessageAvailabilityData {
        if ($message->quarantined_at !== null
            || $message->purged_at !== null
            || $message->revoked_at !== null) {
            return $this->state(MessageAvailabilityState::Unavailable);
        }

        $kind = $message->kind instanceof MessageKind
            ? $message->kind->value
            : strtoupper((string) $message->kind);
        if ($kind === MessageKind::Unsupported->value) {
            return $this->state(MessageAvailabilityState::Unsupported);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (array_key_exists('view_once', $metadata) && $metadata['view_once'] !== false) {
            return $this->state(MessageAvailabilityState::Unavailable);
        }
        $mediaState = strtoupper(trim((string) ($metadata['media_state'] ?? '')));
        $hasAvailableAttachment ??= $message->relationLoaded('attachments')
            ? $message->attachments->contains(
                fn (CommunicationAttachment $attachment): bool => $this->isAttachmentAvailable($attachment),
            )
            : $message->attachments()
                ->whereNull('purged_at')
                ->get()
                ->contains(
                    fn (CommunicationAttachment $attachment): bool => $this->isAttachmentAvailable($attachment),
                );
        if ($hasAvailableAttachment) {
            return $this->state(MessageAvailabilityState::Available);
        }

        if (in_array($kind, self::MEDIA_KINDS, true)) {
            return match ($mediaState) {
                'RETRY_AVAILABLE' => $this->state(MessageAvailabilityState::MediaRetryAvailable, true),
                'REQUESTED' => $this->state(MessageAvailabilityState::MediaRequested),
                'FAILED' => $this->state(
                    MessageAvailabilityState::MediaFailed,
                    ! in_array(strtoupper(trim((string) ($metadata['media_error_code'] ?? ''))), self::TERMINAL_MEDIA_ERRORS, true),
                ),
                'UNAVAILABLE' => $this->state(MessageAvailabilityState::Unavailable),
                default => $this->state(MessageAvailabilityState::Unavailable),
            };
        }

        return $this->hasSemanticContent($message)
            ? $this->state(MessageAvailabilityState::Available)
            : $this->state(MessageAvailabilityState::Unavailable);
    }

    public function isRecoverable(CommunicationMessage $message): bool
    {
        return $this->forMessage($message)->recoverable;
    }

    public function isAttachmentAvailable(CommunicationAttachment $attachment): bool
    {
        if ($attachment->purged_at !== null) {
            return false;
        }

        try {
            return $this->media->exists((string) $attachment->object_id);
        } catch (RuntimeException) {
            return false;
        }
    }

    private function state(
        MessageAvailabilityState $state,
        bool $recoverable = false,
    ): MessageAvailabilityData {
        return new MessageAvailabilityData($state, $recoverable);
    }

    private function hasSemanticContent(CommunicationMessage $message): bool
    {
        if (trim((string) $message->body_encrypted) !== '') {
            return true;
        }

        $content = is_array($message->content_encrypted) ? $message->content_encrypted : [];

        return array_filter(
            $content,
            static fn (mixed $value): bool => match (true) {
                is_string($value) => trim($value) !== '',
                $value === null || $value === [] => false,
                default => true,
            },
        ) !== [];
    }
}
