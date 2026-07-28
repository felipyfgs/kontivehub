<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxMessage */
final class MailboxMessageDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxMessage $message */
        $message = $this->resource;
        $data = (new MailboxMessageResource($message))->resolve($request);

        return $data + [
            'body_content_type' => $message->body_content_type,
            'body_sha256' => $message->body_sha256,
            'retention_until' => $message->retention_until
                ?->toIso8601String(),
            'official_read_observed_at' => $message
                ->official_read_observed_at
                ?->toIso8601String(),
            'triage_by' => $message->triage_by,
            'triage_at' => $message->triage_at?->toIso8601String(),
            'triage_note' => $message->triage_note,
            'attachments' => $message->relationLoaded('attachments')
                ? MailboxAttachmentResource::collection(
                    $message->attachments,
                )->resolve($request)
                : [],
        ];
    }
}
