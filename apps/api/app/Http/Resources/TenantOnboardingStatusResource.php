<?php

namespace App\Http\Resources;

use App\DTO\Tenant\OnboardingStatusData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OnboardingStatusData */
final class TenantOnboardingStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OnboardingStatusData $status */
        $status = $this->resource;

        return $status->payload;
    }
}
