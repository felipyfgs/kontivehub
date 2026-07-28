<?php

namespace App\Http\Resources;

use App\DTO\Clients\BulkClientStatusResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BulkClientStatusResult */
final class BulkClientStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BulkClientStatusResult $result */
        $result = $this->resource;

        return [
            'updated' => $result->updated,
            'client_ids' => $result->clientIds,
            'is_active' => $result->isActive,
        ];
    }
}
