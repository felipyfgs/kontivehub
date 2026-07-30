<?php

namespace App\Actions\Communication;

use App\Models\CommunicationAttachment;
use App\Services\Communication\Media\CommunicationMediaStore;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class StreamCommunicationAttachmentAction
{
    public function __construct(
        private readonly CommunicationMediaStore $media,
    ) {}

    public function download(CommunicationAttachment $attachment): StreamedResponse
    {
        return $this->stream($attachment, 'attachment');
    }

    public function preview(CommunicationAttachment $attachment): StreamedResponse
    {
        $mimeType = (string) $attachment->mime_type;
        if (! str_starts_with($mimeType, 'image/')
            && ! str_starts_with($mimeType, 'audio/')
            && ! str_starts_with($mimeType, 'video/')) {
            throw new UnsupportedMediaTypeHttpException(
                'Este tipo de anexo não possui preview inline.',
            );
        }

        return $this->stream($attachment, 'inline');
    }

    private function stream(
        CommunicationAttachment $attachment,
        string $disposition,
    ): StreamedResponse {
        $attachment->loadMissing('message.inbox');
        if ($attachment->purged_at !== null
            || $attachment->message?->purged_at !== null
            || $attachment->message?->revoked_at !== null
            || $attachment->message?->quarantined_at !== null
            || (bool) data_get($attachment->message?->metadata, 'view_once')
            || ! $this->media->exists($attachment->object_id)) {
            throw new NotFoundHttpException;
        }

        $metadata = is_array($attachment->storage_context)
            ? $attachment->storage_context
            : [
                'tenant_id' => (int) $attachment->tenant_id,
                'inbox_id' => (int) $attachment->message->inbox_id,
                'gateway_event_id' => (string) $attachment->message->gateway_event_id,
                'sha256' => $attachment->sha256,
            ];
        $name = $attachment->original_name_encrypted ?: 'anexo-'.$attachment->id;
        $name = basename(str_replace('\\', '/', (string) $name));
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name)
            ?: 'anexo-'.$attachment->id;

        return response()->stream(function () use ($attachment, $metadata): void {
            foreach ($this->media->readChunks($attachment->object_id, $metadata) as $chunk) {
                echo $chunk;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) $attachment->size_bytes,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $name,
                $fallback,
            ),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
