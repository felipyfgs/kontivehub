<?php

namespace App\Http\Resources;

use App\DTO\Serpro\DteCanarySummaryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DteCanarySummaryResult */
final class SerproDteCanarySummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DteCanarySummaryResult $summary */
        $summary = $this->resource;

        return [
            'control' => SerproDteControlResource::make($summary->control),
            'coordinates' => $summary->coordinates,
            'request' => $summary->request !== null
                ? SerproDteCanaryRequestResource::make($summary->request)
                : null,
            'gate' => $summary->gate,
        ];
    }
}
