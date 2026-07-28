<?php

namespace App\Http\Resources;

use App\DTO\Clients\EstablishmentUpdateResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EstablishmentUpdateResult */
final class EstablishmentUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EstablishmentUpdateResult $result */
        $result = $this->resource;
        $payload = EstablishmentResource::make(
            $result->establishment,
        )->resolve($request);
        $payload['capture_eligibility'] = $result->captureEligibility;

        return $payload;
    }
}
