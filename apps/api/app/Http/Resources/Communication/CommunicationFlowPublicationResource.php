<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationFlowPublicationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationFlowPublicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowPublicationResult $result */
        $result = $this->resource;

        return [
            'version' => new CommunicationFlowVersionResource($result->version),
            'flow' => new CommunicationFlowResource($result->flow),
            'bindings_enabled' => $result->enabledBindings,
        ];
    }
}
