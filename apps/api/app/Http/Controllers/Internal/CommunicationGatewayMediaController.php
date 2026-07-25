<?php

namespace App\Http\Controllers\Internal;

use App\Enums\Communication\GatewayCommandType;
use App\Http\Controllers\Controller;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Services\Communication\Security\CommunicationHmacVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunicationGatewayMediaController extends Controller
{
    public function __invoke(
        Request $request,
        string $command,
        CommunicationHmacVerifier $verifier,
        CommunicationMediaStore $media,
    ): StreamedResponse|JsonResponse {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return response()->json(['error' => 'COMMUNICATION_DISABLED'], 503);
        }
        $verification = $verifier->verify(
            $request->method(),
            $request->getPathInfo(),
            '',
            $request->headers->all(),
        );
        if (! $verification->accepted()) {
            return response()->json(['error' => 'INVALID_INTERNAL_SIGNATURE'], 401);
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $command)) {
            return response()->json(['error' => 'MEDIA_NOT_FOUND'], 404);
        }

        $entry = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->with('message.attachments')
            ->where('command_id', $command)
            ->where('type', GatewayCommandType::SendMessage->value)
            ->first();
        $payload = $entry?->payload_encrypted;
        $attachmentId = is_array($payload) ? (int) ($payload['media']['attachment_id'] ?? 0) : 0;
        $attachment = $entry?->message?->attachments->firstWhere('id', $attachmentId);
        if (! $attachment instanceof CommunicationAttachment
            || $attachment->purged_at !== null
            || $entry?->message?->purged_at !== null
            || $entry?->message?->revoked_at !== null
            || ! $media->exists($attachment->object_id)
            || ! is_array($attachment->storage_context)) {
            return response()->json(['error' => 'MEDIA_NOT_FOUND'], 404);
        }
        $filename = preg_replace('/[^\pL\pN._-]+/u', '-', basename((string) $attachment->original_name_encrypted)) ?: 'documento';

        return response()->stream(function () use ($media, $attachment): void {
            foreach ($media->readChunks($attachment->object_id, $attachment->storage_context) as $chunk) {
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
