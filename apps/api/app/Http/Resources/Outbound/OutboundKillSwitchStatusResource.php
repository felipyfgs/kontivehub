<?php

namespace App\Http\Resources\Outbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundKillSwitchStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $status */
        $status = $this->resource;

        return $status;
    }
}
