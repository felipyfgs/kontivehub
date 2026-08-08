<?php

namespace App\Http\Resources\Communication;

use App\Services\Communication\Contact\SharedVCardParser;
use App\Services\Communication\MessageAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('attachments');
        $availabilityService = app(MessageAvailability::class);
        $viewOnce = (bool) data_get($this->metadata, 'view_once', false);
        $availableAttachments = $viewOnce
            ? $this->attachments->take(0)
            : $this->attachments
                ->filter(
                    fn ($attachment): bool => $availabilityService->isAttachmentAvailable($attachment),
                )
                ->values();
        $availability = $availabilityService->forMessage(
            $this->resource,
            $availableAttachments->isNotEmpty(),
        );
        $available = $availability->state->value === 'AVAILABLE';
        $contentVisible = $available || $availability->state->value === 'UNSUPPORTED';

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction?->value ?? $this->direction,
            'kind' => $this->kind?->value ?? $this->kind,
            'provider_type' => $this->provider_type,
            'source' => $this->source?->value ?? $this->source,
            'status' => $this->status?->value ?? $this->status,
            'body' => $available ? $this->body_encrypted : null,
            'content' => $contentVisible ? $this->safeContent() : null,
            'availability' => $availability->toArray(),
            'reply_to_message_id' => $this->reply_to_message_id,
            'author_membership_id' => $this->author_membership_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'played_at' => $this->played_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'metadata' => $this->safeMetadata(),
            'attachments' => ! $available ? [] : $availableAttachments->map(fn ($attachment) => [
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
            ])->values(),
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
        $gatewayActions = is_array($metadata['gateway_actions'] ?? null)
            ? array_slice($metadata['gateway_actions'], -10, null, true)
            : [];
        $allowed['gateway_actions'] = array_values(array_filter(array_map(
            static function (mixed $action, mixed $commandId): ?array {
                if (! is_string($commandId)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $commandId) !== 1
                    || ! is_array($action)
                    || ! in_array($action['action'] ?? null, ['EDIT', 'REACTION', 'REVOKE'], true)
                    || ! in_array($action['status'] ?? null, ['PENDING', 'SUCCEEDED', 'FAILED'], true)) {
                    return null;
                }

                return array_filter([
                    'command_id' => $commandId,
                    'action' => $action['action'],
                    'status' => $action['status'],
                    'requested_at' => is_string($action['requested_at'] ?? null) ? $action['requested_at'] : null,
                    'completed_at' => is_string($action['completed_at'] ?? null) ? $action['completed_at'] : null,
                    'error_code' => in_array($action['error_code'] ?? null, [
                        'ACTION_REJECTED', 'ACTION_RETRY_EXHAUSTED', 'ACTION_OUTCOME_UNKNOWN',
                    ], true) ? $action['error_code'] : null,
                ], static fn (mixed $value): bool => $value !== null);
            },
            $gatewayActions,
            array_keys($gatewayActions),
        )));

        return array_filter($allowed, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string,mixed>|null */
    private function safeContent(): ?array
    {
        $content = is_array($this->content_encrypted) ? $this->content_encrypted : [];
        $allowed = array_intersect_key($content, array_flip([
            'text', 'caption', 'link_preview', 'location', 'contacts', 'poll', 'event', 'interactive', 'rich_card',
            'ptt', 'gif', 'animated', 'duration_seconds', 'content_present', 'variants',
            'interactive_response',
        ]));
        if (is_array($allowed['contacts'] ?? null)) {
            $parser = app(SharedVCardParser::class);
            $allowed['contacts'] = array_map(static function (mixed $contact) use ($parser): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }
                $presented = $parser->parse(
                    (string) ($contact['vcard'] ?? ''),
                    is_string($contact['display_name'] ?? null) ? $contact['display_name'] : null,
                );

                return [
                    'display_name' => $presented['display_name'],
                    'vcard' => substr((string) ($contact['vcard'] ?? ''), 0, 65_536),
                    'phones' => $presented['phones'],
                ];
            }, $allowed['contacts']);
        }
        $allowed['reactions'] = array_values(array_filter(
            is_array($content['reactions'] ?? null) ? $content['reactions'] : [],
            'is_string',
        ));
        if (is_array($content['poll_votes'] ?? null)) {
            $allowed['poll_votes'] = array_values(array_map(static function (mixed $vote): array {
                $vote = is_array($vote) ? $vote : [];

                return [
                    'option_names' => array_slice(array_values(array_filter(
                        is_array($vote['option_names'] ?? null) ? $vote['option_names'] : [],
                        static fn (mixed $value): bool => is_string($value) && strlen($value) <= 1024,
                    )), 0, 12),
                    'option_hashes' => array_slice(array_values(array_filter(
                        is_array($vote['option_hashes'] ?? null) ? $vote['option_hashes'] : [],
                        static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1,
                    )), 0, 12),
                ];
            }, $content['poll_votes']));
        }

        $filtered = array_filter($allowed, static fn (mixed $value): bool => $value !== null && $value !== []);

        return $filtered === [] ? null : $filtered;
    }

    private function supportsInlinePreview(string $mime): bool
    {
        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/');
    }
}
