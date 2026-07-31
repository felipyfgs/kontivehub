<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationFlowInboxBinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlowBindingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowInboxBinding $binding */
        $binding = $this->resource;

        return [
            'id' => (int) $binding->id,
            'flow_id' => (int) $binding->flow_id,
            'inbox_id' => (int) $binding->inbox_id,
            'published_version_id' => $binding->published_version_id !== null
                ? (int) $binding->published_version_id
                : null,
            'enabled' => (bool) $binding->enabled,
            'lock_version' => (int) $binding->lock_version,
        ];
    }
}
