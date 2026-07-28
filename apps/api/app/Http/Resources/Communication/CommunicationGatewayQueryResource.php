<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationGatewayQueryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationGatewayQueryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationGatewayQueryResult $result */
        $result = $this->resource;

        return $result->data;
    }
}
