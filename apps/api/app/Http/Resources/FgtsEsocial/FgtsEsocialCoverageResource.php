<?php

namespace App\Http\Resources\FgtsEsocial;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialCoverageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
