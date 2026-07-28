<?php

namespace App\Http\Resources\Outbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundPayloadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
