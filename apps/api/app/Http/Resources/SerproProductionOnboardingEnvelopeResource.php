<?php

namespace App\Http\Resources;

use App\DTO\Serpro\SerproProductionOnboardingEnvelopeData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproProductionOnboardingEnvelopeData */
final class SerproProductionOnboardingEnvelopeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproProductionOnboardingEnvelopeData $envelope */
        $envelope = $this->resource;

        return [
            'enabled' => $envelope->enabled,
            'tenant_id' => $envelope->tenantId,
            'consent' => $envelope->consent,
            'onboarding' => $envelope->onboarding !== null
                ? SerproProductionOnboardingResource::make($envelope->onboarding)->resolve($request)
                : null,
        ];
    }
}
