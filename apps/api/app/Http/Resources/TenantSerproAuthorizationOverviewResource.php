<?php

namespace App\Http\Resources;

use App\DTO\Tenant\SerproAuthorizationOverviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproAuthorizationOverviewData */
final class TenantSerproAuthorizationOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproAuthorizationOverviewData $overview */
        $overview = $this->resource;

        return TenantSerproAuthorizationResource::make(
            $overview->authorization,
        )->resolve($request);
    }

    public function with(Request $request): array
    {
        /** @var SerproAuthorizationOverviewData $overview */
        $overview = $this->resource;

        return [
            'platform_health' => $overview->platformHealth,
            'onboarding' => $overview->onboarding,
            'actionable' => $overview->actionable,
            'platform_available' => $overview->platformAvailable,
            'term_representation_strategy' => $overview->termRepresentationStrategy,
        ];
    }
}
