<?php

namespace App\Http\Resources;

use App\Models\SerproCredentialConnectionEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproCredentialConnectionEvidence */
final class SerproCredentialConnectionEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproCredentialConnectionEvidence $evidence */
        $evidence = $this->resource;

        return $evidence->toSanitizedArray();
    }
}
