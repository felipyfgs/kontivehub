<?php

namespace App\Http\Resources;

use App\DTO\Clients\ClientCategoryReplacementResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCategoryReplacementResult */
final class ClientCategoryReplacementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCategoryReplacementResult $result */
        $result = $this->resource;

        return [
            'client_id' => (int) $result->client->id,
            'categories' => ClientCategoryResource::collection(
                $result->client->categories,
            )->resolve($request),
            'added' => $result->added,
            'removed' => $result->removed,
        ];
    }
}
