<?php

namespace App\Http\Resources\Work;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OperationResultResource extends JsonResource
{
    /** @return array<string, bool> */
    public function toArray(Request $request): array
    {
        /** @var array<string, bool> $result */
        $result = $this->resource;

        return $result;
    }
}
