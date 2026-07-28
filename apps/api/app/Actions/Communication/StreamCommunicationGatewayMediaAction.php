<?php

namespace App\Actions\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Media\CommunicationMediaStore;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class StreamCommunicationGatewayMediaAction
{
    public function __construct(
        private CommunicationMediaStore $media,
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
        $payload = $entry?->payload_encrypted;
        $attachmentId = is_array($payload) ? (int) ($payload['media']['attachment_id'] ?? 0) : 0;
        $message = $entry?->message;
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

        return response()->stream(function () use ($attachment): void {
            foreach ($this->media->readChunks($attachment->object_id, $attachment->storage_context) as $chunk) {
                echo $chunk;
                flush();
            }
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) $attachment->size_bytes,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $attachment->sha256,
        ]);
    }
}
