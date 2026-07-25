<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $available = $this->purged_at === null && $this->revoked_at === null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction?->value ?? $this->direction,
            'kind' => $this->kind?->value ?? $this->kind,
            'provider_type' => $this->provider_type,
            'source' => $this->source?->value ?? $this->source,
            'status' => $this->status?->value ?? $this->status,
            'body' => $available ? $this->body_encrypted : null,
            'content' => $available ? $this->safeContent() : null,
            'reply_to_message_id' => $this->reply_to_message_id,
            'author_membership_id' => $this->author_membership_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'played_at' => $this->played_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'metadata' => $this->safeMetadata(),
            'attachments' => $this->whenLoaded('attachments', fn () => ! $available ? [] : $this->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->original_name_encrypted ?: 'anexo-'.$attachment->id,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => (int) $attachment->size_bytes,
                'sha256' => $attachment->sha256,
                'download_url' => '/api/v1/communication/attachments/'.$attachment->id.'/download',
                'preview_url' => $this->supportsInlinePreview((string) $attachment->mime_type)
                    ? '/api/v1/communication/attachments/'.$attachment->id.'/preview'
                    : null,
                'purged_at' => $attachment->purged_at?->toIso8601String(),
            ])->values()),
        ];
    }

    /** @return array<string,mixed> */
    private function safeMetadata(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $allowed = array_intersect_key($metadata, array_flip([
            'edited_at',
            'revoked',
            'history',
            'ephemeral',
            'view_once',
            'media_state',
            'media_error_code',
        ]));

        return array_filter($allowed, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string,mixed> */
    private function safeContent(): array
    {
        $content = is_array($this->content_encrypted) ? $this->content_encrypted : [];
        $allowed = array_intersect_key($content, array_flip([
            'text', 'caption', 'link_preview', 'location', 'contacts', 'poll', 'interactive',
            'ptt', 'gif', 'animated', 'duration_seconds', 'content_present', 'variants',
            'interactive_response',
        ]));
        $allowed['reactions'] = array_values(array_filter(
            is_array($content['reactions'] ?? null) ? $content['reactions'] : [],
            'is_string',
        ));
        if (is_array($content['poll_votes'] ?? null)) {
            $allowed['poll_votes'] = array_values($content['poll_votes']);
        }

        return array_filter($allowed, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function supportsInlinePreview(string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/');
    }
}
