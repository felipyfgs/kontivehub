<?php

namespace App\Actions\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final readonly class StreamGatewayMediaAction
{
    public function __construct(
        private MediaStore $media,
    ) {}

    public function execute(string $command): ?StreamedResponse
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $command)) {
            return null;
        }

        $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->with([
                'message' => fn ($query) => $query->withoutGlobalScopes(),
                'message.attachments' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('command_id', $command)
            ->where('type', GatewayCommandType::SendMessage->value)
            ->first();
        [$entry, $message, $attachmentId] = $entry instanceof CommunicationOutboxEntry
            ? $this->singularMedia($entry)
            : $this->batchMedia($command);
        $attachment = $message?->attachments->firstWhere('id', $attachmentId);
        if (! $attachment instanceof CommunicationAttachment
            || (int) $message->tenant_id !== (int) $entry->tenant_id
            || (int) $attachment->tenant_id !== (int) $entry->tenant_id
            || $attachment->purged_at !== null
            || $message->purged_at !== null
            || $message->revoked_at !== null
            || ! $this->media->exists($attachment->object_id)
            || ! is_array($attachment->storage_context)) {
            return null;
        }

        $filename = preg_replace(
            '/[^\pL\pN._-]+/u',
            '-',
            basename((string) $attachment->original_name_encrypted),
        ) ?: 'documento';

        $size = (int) $attachment->size_bytes;
        $start = 0;
        $end = $size > 0 ? $size - 1 : -1;

        try {
            // Stream chunk-by-chunk after integrity validation; avoid materializing the
            // entire attachment as a single PHP string (OOM risk under concurrency).
            $chunks = $this->media->readValidatedRange(
                $attachment->object_id,
                $attachment->storage_context,
                $start,
                $end,
                $size,
                (string) $attachment->sha256,
            );
            // Generators are lazy: force the integrity pass before HTTP headers are sent.
            $chunks->rewind();
        } catch (Throwable $error) {
            Log::warning('communication.media.gateway_stream_unavailable', [
                'error_code' => 'MEDIA_STREAM_UNAVAILABLE',
                'error_class' => $error::class,
                'attachment_id' => (int) $attachment->id,
                'message_id' => (int) $attachment->message_id,
            ]);

            throw new ServiceUnavailableHttpException(
                retryAfter: 1,
                message: 'Mídia temporariamente indisponível.',
                previous: $error,
            );
        }

        return response()->stream(function () use ($chunks): void {
            if (! $chunks->valid()) {
                return;
            }

            do {
                $chunk = $chunks->current();
                if (is_string($chunk) && $chunk !== '') {
                    echo $chunk;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }
                $chunks->next();
            } while ($chunks->valid());
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) $attachment->size_bytes,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $attachment->sha256,
        ]);
    }

    /** @return array{0:CommunicationOutboxEntry,1:CommunicationMessage|null,2:int} */
    private function singularMedia(CommunicationOutboxEntry $entry): array
    {
        $payload = $entry->payload_encrypted;

        return [
            $entry,
            $entry->message,
            is_array($payload) ? (int) ($payload['media']['attachment_id'] ?? 0) : 0,
        ];
    }

    /** @return array{0:CommunicationOutboxEntry|null,1:CommunicationMessage|null,2:int} */
    private function batchMedia(string $providerMessageId): array
    {
        $messages = CommunicationMessage::query()->withoutGlobalScopes()
            ->with([
                'attachments' => fn ($query) => $query->withoutGlobalScopes(),
                'batch.outboxEntry' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('provider_message_id', $providerMessageId)
            ->whereNotNull('message_batch_id')
            ->limit(2)
            ->get();
        if ($messages->count() !== 1) {
            return [null, null, 0];
        }

        $message = $messages->first();
        $entry = $message?->batch?->outboxEntry;
        if (! $message instanceof CommunicationMessage
            || ! $entry instanceof CommunicationOutboxEntry
            || $entry->type !== GatewayCommandType::SendMessageBatch) {
            return [null, null, 0];
        }
        $payload = $entry->payload_encrypted;
        $items = is_array($payload) && is_array($payload['items'] ?? null)
            ? $payload['items']
            : [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ! hash_equals((string) ($item['provider_message_id'] ?? ''), $providerMessageId)
                || (int) ($item['position'] ?? -1) !== (int) $message->batch_position
                || (string) ($item['batch_id'] ?? '') !== (string) data_get($message->metadata, 'batch_id')) {
                continue;
            }

            return [
                $entry,
                $message,
                (int) data_get($item, 'message.media.attachment_id', 0),
            ];
        }

        return [null, null, 0];
    }
}
