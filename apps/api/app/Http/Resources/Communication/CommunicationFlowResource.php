<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\FlowStatus;
use App\Models\CommunicationFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationFlowResource extends JsonResource
{
    public function __construct(
        mixed $resource,
        private readonly bool $detailed = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlow $flow */
        $flow = $this->resource;
        $payload = [
            'id' => (int) $flow->id,
            'name' => $flow->name,
            'status' => $flow->status instanceof FlowStatus
                ? $flow->status->value
                : (string) $flow->status,
            'lock_version' => (int) $flow->lock_version,
            'created_at' => $flow->created_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
        ];
        if ($this->detailed) {
            $payload['draft'] = $flow->draft !== null
                ? new CommunicationFlowDraftResource($flow->draft)
                : null;
            $payload['versions'] = CommunicationFlowVersionResource::collection(
                $flow->versions,
            );
            $payload['bindings'] = CommunicationFlowBindingResource::collection(
                $flow->bindings,
            );
        }

        return $payload;
    }
}
