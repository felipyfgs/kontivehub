<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundKillSwitchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundKillSwitchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundKillSwitchResult $result */
        $result = $this->resource;
        if ($result->profile !== null) {
            return (new OutboundCaptureProfileResource($result->profile))
                ->resolve($request);
        }

        return [
            'global_active' => (bool) $result->globalActive,
            'position_kind' => 'nNF',
        ];
    }
}
