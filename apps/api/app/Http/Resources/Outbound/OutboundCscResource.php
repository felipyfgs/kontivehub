<?php

namespace App\Http\Resources\Outbound;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCscResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $state */
        $state = $this->resource;

        return [
            'configured' => (bool) ($state['configured'] ?? false),
            'csc_id' => $state['csc_id'] ?? null,
            'configured_at' => $state['configured_at'] ?? null,
            'csc' => $state['csc'] ?? null,
        ];
    }
}
