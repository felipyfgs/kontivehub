<?php

namespace App\Http\Resources;

use App\Models\SerproProductionOnboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SerproProductionOnboarding
 */
class SerproProductionOnboardingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SerproProductionOnboarding $onboarding */
        $onboarding = $this->resource;

        return $onboarding->toSanitizedArray();
    }
}
