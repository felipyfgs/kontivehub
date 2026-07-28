<?php

namespace App\Http\Resources;

use App\DTO\Serpro\UsageLimitsUpdateResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UsageLimitsUpdateResult */
final class SerproUsageLimitsUpdateResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var UsageLimitsUpdateResult $result */
        $result = $this->resource;

        return [
            'config' => SerproQuantityUsageLimitResource::make($result->configuration),
            'tenant_limits' => $result->tenantLimits,
        ];
    }
}
