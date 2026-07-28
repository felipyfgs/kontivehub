<?php

namespace App\Http\Resources\FgtsDigital;

use App\Models\FgtsDigitalRepresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalRepresentationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalRepresentation $representation */
        $representation = $this->resource;

        return [
            'id' => $representation->id,
            'client_id' => $representation->client_id,
            'status' => $representation->status->value,
            'valid_to' => $representation->valid_to?->toIso8601String(),
            'credential_source' => $representation->credential_source->value,
        ];
    }
}
