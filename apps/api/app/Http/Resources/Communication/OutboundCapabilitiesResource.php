<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\OutboundCapabilitiesData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCapabilitiesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCapabilitiesData $capabilities */
        $capabilities = $this->resource;

        return $capabilities->toArray();
    }
}
