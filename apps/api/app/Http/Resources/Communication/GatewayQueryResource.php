<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\GatewayQueryResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GatewayQueryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var GatewayQueryResult $result */
        $result = $this->resource;

        return $result->data;
    }
}
