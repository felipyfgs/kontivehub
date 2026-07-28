<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxAlert */
final class MailboxAlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxAlert $alert */
        $alert = $this->resource;

        return [
            'id' => $alert->id,
            'tenant_id' => $alert->tenant_id,
            'client_id' => $alert->client_id,
            'mailbox_message_id' => $alert->mailbox_message_id,
            'severity' => $alert->severity?->value,
            'title' => $alert->title,
            'body' => $alert->body,
            'deep_link' => $alert->deep_link,
            'is_active' => $alert->is_active,
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }
}
