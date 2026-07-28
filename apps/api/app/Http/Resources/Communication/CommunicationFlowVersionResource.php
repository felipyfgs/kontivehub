<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationFlowVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationFlowVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowVersion $version */
        $version = $this->resource;

        return [
            'id' => (int) $version->id,
            'flow_id' => (int) $version->flow_id,
            'version' => (int) $version->version,
            'graph_digest' => $version->graph_digest,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }
}
