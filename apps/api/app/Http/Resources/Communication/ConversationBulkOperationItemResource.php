<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\ConversationBulkItemStatus;
use App\Models\CommunicationConversationBulkOperationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommunicationConversationBulkOperationItem */
final class ConversationBulkOperationItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ConversationBulkItemStatus
            ? $this->status->value
            : (string) $this->status;

        return [
            'id' => $this->id,
            'item_index' => (int) $this->item_index,
            'conversation_id' => (int) $this->conversation_id,
            'resolved_conversation_id' => $this->resolved_conversation_id !== null
                ? (int) $this->resolved_conversation_id
                : null,
            'inbox_id' => (int) $this->inbox_id,
            'status' => $status,
            'result_code' => $this->result_code,
            'result_message' => $this->result_message,
            'attempts' => (int) $this->attempts,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
