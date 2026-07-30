<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Models\CommunicationConversationBulkOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommunicationConversationBulkOperation */
final class ConversationBulkOperationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ConversationBulkOperationStatus
            ? $this->status->value
            : (string) $this->status;
        $action = $this->action instanceof ConversationBulkAction
            ? $this->action->value
            : (string) $this->action;
        $terminal = $this->status instanceof ConversationBulkOperationStatus
            ? $this->status->isTerminal()
            : in_array($status, ['COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED'], true);
        $processed = (int) $this->succeeded_count
            + (int) $this->skipped_count
            + (int) $this->failed_count;

        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'action' => $action,
            'params' => $this->params ?? [],
            'status' => $status,
            'is_terminal' => $terminal,
            'item_count' => (int) $this->item_count,
            'processed_count' => $processed,
            'succeeded_count' => (int) $this->succeeded_count,
            'skipped_count' => (int) $this->skipped_count,
            'failed_count' => (int) $this->failed_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
