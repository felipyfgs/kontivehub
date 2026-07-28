<?php

namespace App\Http\Resources;

use App\Models\SerproCredentialVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproCredentialVersion */
final class SerproCredentialVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproCredentialVersion $version */
        $version = $this->resource;

        return $version->toSanitizedArray();
    }
}
