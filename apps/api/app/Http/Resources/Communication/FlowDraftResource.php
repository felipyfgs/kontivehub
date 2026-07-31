<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationFlowDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlowDraftResource extends JsonResource
{
    private const EMPTY_GRAPH = ['nodes' => [], 'edges' => []];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowDraft $draft */
        $draft = $this->resource;

        return [
            'id' => (int) $draft->id,
            'flow_id' => (int) $draft->flow_id,
            'graph' => is_array($draft->graph_encrypted)
                ? $draft->graph_encrypted
                : self::EMPTY_GRAPH,
            'graph_digest' => $draft->graph_digest,
            'lock_version' => (int) $draft->lock_version,
            'updated_at' => $draft->updated_at?->toIso8601String(),
        ];
    }
}
