<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationEvent $event */
        $event = $this->resource;

        return [
            'cursor' => (int) $event->id,
            'type' => $event->type,
            'inbox_id' => $event->inbox_id,
            'conversation_id' => $event->conversation_id,
            'message_id' => $event->message_id,
            'payload' => $event->payload ?? [],
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
