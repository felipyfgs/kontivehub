<?php

namespace App\Http\Resources;

use App\DTO\Clients\BulkClientCategoryUpdateResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BulkClientCategoryUpdateResult */
final class BulkClientCategoryUpdateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BulkClientCategoryUpdateResult $result */
        $result = $this->resource;

        return [
            'operation' => $result->operation,
            'updated_clients' => count($result->clientIds),
            'client_ids' => $result->clientIds,
            'category_ids' => $result->categoryIds,
            'created_links' => $result->createdLinks,
            'removed_links' => $result->removedLinks,
        ];
    }
}
