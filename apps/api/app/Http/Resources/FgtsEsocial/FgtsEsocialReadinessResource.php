<?php

namespace App\Http\Resources\FgtsEsocial;

use App\DTO\Esocial\EsocialBxReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EsocialBxReadiness $readiness */
        $readiness = $this->resource;

        return $readiness->toArray();
    }
}
