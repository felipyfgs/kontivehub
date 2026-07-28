<?php

namespace App\Http\Resources;

use App\DTO\Clients\ClientRegistrationRefreshResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientRegistrationRefreshResult */
final class ClientRegistrationRefreshResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientRegistrationRefreshResult $result */
        $result = $this->resource;
        $payload = ClientResource::make($result->client)->resolve($request);
        $payload['establishments'] = EstablishmentResource::collection(
            $result->client->establishments,
        )->resolve($request);
        $payload['lookup'] = $result->lookup;

        return $payload;
    }
}
