<?php

namespace App\Http\Resources;

use App\DTO\Clients\ClientCreationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCreationResult */
final class ClientCreationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCreationResult $result */
        $result = $this->resource;

        return [
            'client' => ClientResource::make($result->client)->resolve($request),
            'establishment' => EstablishmentResource::make($result->establishment)->resolve($request),
            'contact' => $result->contact !== null
                ? ClientContactResource::make($result->contact)->resolve($request)
                : null,
            'custom_fields' => ClientCustomFieldResource::collection(
                collect($result->customFields),
            )->resolve($request),
        ];
    }
}
