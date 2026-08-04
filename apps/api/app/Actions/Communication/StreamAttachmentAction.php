<?php

namespace App\Actions\Communication;

use App\Models\CommunicationAttachment;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class StreamAttachmentAction
{
    public function __construct(
        private readonly MediaStore $media,
    ) {}

    public function download(CommunicationAttachment $attachment, Request $request): Response
    {
        return $this->stream($attachment, $request, 'attachment');
    }

    public function preview(CommunicationAttachment $attachment, Request $request): Response
    {
        $mimeType = (string) $attachment->mime_type;
        if (! str_starts_with($mimeType, 'image/')
            && ! str_starts_with($mimeType, 'audio/')
            && ! str_starts_with($mimeType, 'video/')) {
            throw new UnsupportedMediaTypeHttpException(
                'Este tipo de anexo não possui preview inline.',
            );
        }

        return $this->stream($attachment, $request, 'inline');
    }

    private function stream(
        CommunicationAttachment $attachment,
        Request $request,
        string $disposition,
    ): Response {
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

        $size = (int) $attachment->size_bytes;
        $range = $this->parseRange($request->header('Range'), $size);
        if ($range === false) {
            return response('', 416, [
                'Content-Range' => 'bytes */'.$size,
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        [$start, $end] = $range ?? ($size > 0 ? [0, $size - 1] : [0, -1]);
        $status = $range === null ? 200 : 206;
        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Length' => (string) max(0, $end - $start + 1),
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $name, $fallback),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($range !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        if ($request->isMethod('HEAD') || $size === 0) {
            return response('', $status, $headers);
        }

        return response()->stream(function () use ($attachment, $metadata, $start, $end): void {
            foreach ($this->media->readRangeChunks($attachment->object_id, $metadata, $start, $end) as $chunk) {
                echo $chunk;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, $status, $headers);
    }

    /** @return array{0:int,1:int}|null|false */
    private function parseRange(?string $header, int $size): array|null|false
    {
        if ($header === null || trim($header) === '') {
            return null;
        }
        if (str_contains($header, ',')) {
            return null;
        }
        if ($size < 1
            || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }
        if ($matches[1] === '') {
            $suffix = (int) $matches[2];
            if ($suffix < 1) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];
        if ($start >= $size || $end < $start) {
            return false;
        }

        return [$start, min($end, $size - 1)];
    }
}
