<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxAttachment */
final class MailboxAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxAttachment $attachment */
        $attachment = $this->resource;

        return [
            'id' => $attachment->id,
            'tenant_id' => $attachment->tenant_id,
            'mailbox_message_id' => $attachment->mailbox_message_id,
            'external_id' => $attachment->external_id,
            'filename_sanitized' => $attachment->filename_sanitized,
            'content_type' => $attachment->content_type,
            'content_sha256' => $attachment->content_sha256,
            'byte_size' => $attachment->byte_size,
            'sensitivity_class' => $attachment->sensitivity_class,
            'retention_until' => $attachment->retention_until
                ?->toIso8601String(),
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }
}
