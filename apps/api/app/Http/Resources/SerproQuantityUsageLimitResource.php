<?php

namespace App\Http\Resources;

use App\Models\SerproQuantityUsageLimit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproQuantityUsageLimit */
final class SerproQuantityUsageLimitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproQuantityUsageLimit $limit */
        $limit = $this->resource;

        return $limit->toSanitizedArray();
    }
}
