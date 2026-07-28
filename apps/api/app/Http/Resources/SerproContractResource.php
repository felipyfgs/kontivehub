<?php

namespace App\Http\Resources;

use App\Models\SerproContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproContract */
final class SerproContractResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproContract $contract */
        $contract = $this->resource;

        return $contract->toSanitizedArray();
    }
}
