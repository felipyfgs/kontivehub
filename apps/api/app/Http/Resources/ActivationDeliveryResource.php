<?php

namespace App\Http\Resources;

use App\DTO\Platform\ActivationDeliveryResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ActivationDeliveryResult */
final class ActivationDeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ActivationDeliveryResult $result */
        $result = $this->resource;

        return $result->payload;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        /** @var ActivationDeliveryResult $result */
        $result = $this->resource;

        $response->setStatusCode($result->httpStatus);
        $response->headers->set('Cache-Control', 'no-store');
    }
}
