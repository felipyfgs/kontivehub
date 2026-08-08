<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MessageBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'batch_id' => $this->gatewayBatchId(),
            'client_batch_id' => (string) $this->client_batch_id,
            'conversation_id' => (int) $this->conversation_id,
            'status' => (string) $this->status,
            'item_count' => (int) $this->item_count,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'created_at' => $this->created_at?->toAtomString(),
        ];
    }
}
