<?php

namespace App\Http\Resources\Work;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcessTemplateCatalogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $definition */
        $definition = $this->resource;

        return $definition;
    }
}
