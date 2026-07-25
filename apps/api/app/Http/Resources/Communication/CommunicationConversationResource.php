<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inbox_id' => $this->inbox_id,
            'status' => $this->status?->value ?? $this->status,
            'work_department_id' => $this->work_department_id,
            'assignee_membership_id' => $this->assignee_membership_id,
            'priority' => (int) $this->priority,
            'snoozed_until' => $this->snoozed_until?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'lock_version' => (int) $this->lock_version,
            'messages_count' => $this->whenCounted('messages'),
            'contact' => $this->whenLoaded('identity', fn () => [
                'id' => $this->identity->contact_id,
                'name' => $this->identity->relationLoaded('contact') ? $this->identity->contact?->name : null,
                'is_provisional' => $this->identity->relationLoaded('contact')
                    ? (bool) $this->identity->contact?->is_provisional
                    : null,
                'address_masked' => $this->identity->address_masked,
                'address' => $this->identity->address_encrypted,
            ]),
            'clients' => $this->whenLoaded('clients', fn () => $this->clients->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->display_name ?: $client->legal_name,
            ])->values()),
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ])->values()),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage
                ? new CommunicationMessageResource($this->latestMessage)
                : null),
            'messages' => CommunicationMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
