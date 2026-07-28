<?php

namespace App\Http\Resources;

use App\Models\SerproExternalGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproExternalGate */
final class SerproExternalGateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproExternalGate $gate */
        $gate = $this->resource;

        return $gate->toSanitizedArray();
    }
}
