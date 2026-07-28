<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantOnboardingStatusData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantOnboardingStatusData */
final class TenantOnboardingStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantOnboardingStatusData $status */
        $status = $this->resource;

        return $status->payload;
    }
}
