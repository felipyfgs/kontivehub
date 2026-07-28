<?php

namespace App\Http\Resources\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalReadinessData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalReadinessData $readiness */
        $readiness = $this->resource;

        return $readiness->toArray();
    }
}
