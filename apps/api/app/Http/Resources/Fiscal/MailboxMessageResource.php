<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxMessage */
final class MailboxMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxMessage $message */
        $message = $this->resource;

        return [
            'id' => $message->id,
            'tenant_id' => $message->tenant_id,
            'client_id' => $message->client_id,
            'external_id' => $message->external_id,
            'source' => $message->source?->value,
            'sensitivity_class' => $message->sensitivity_class,
            'category_code' => $message->category_code,
            'category_label' => $message->category_label,
            'sender_code' => $message->sender_code,
            'sender_label' => $message->sender_label,
            'subject_preview' => $message->subject_preview,
            'received_at_official' => $message->received_at_official
                ?->toIso8601String(),
            'due_at' => $message->due_at?->toIso8601String(),
            'severity_hint' => $message->severity_hint,
            'official_read_indicator' => $message->official_read_indicator,
            'triage_status' => $message->triage_status?->value,
            'has_body' => $message->has_body,
            'attachment_count' => $message->attachment_count,
            'body_byte_size' => $message->body_byte_size,
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
        ];
    }
}
