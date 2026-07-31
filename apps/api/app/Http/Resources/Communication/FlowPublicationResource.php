<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\FlowPublicationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlowPublicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FlowPublicationResult $result */
        $result = $this->resource;

        return [
            'version' => new FlowVersionResource($result->version),
            'flow' => new FlowResource($result->flow),
            'bindings_enabled' => $result->enabledBindings,
        ];
    }
}
