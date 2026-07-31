<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\FlowRunStatus;
use App\Models\CommunicationFlowRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlowRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowRun $run */
        $run = $this->resource;

        return [
            'id' => (int) $run->id,
            'flow_id' => (int) $run->flow_id,
            'flow_version_id' => (int) $run->flow_version_id,
            'binding_id' => $run->binding_id !== null ? (int) $run->binding_id : null,
            'conversation_id' => $run->conversation_id !== null
                ? (int) $run->conversation_id
                : null,
            'status' => $run->status instanceof FlowRunStatus
                ? $run->status->value
                : (string) $run->status,
            'current_node_id' => $run->current_node_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'waiting_until' => $run->waiting_until?->toIso8601String(),
        ];
    }
}
