<?php

namespace App\Http\Resources;

use App\DTO\Work\ProcessViewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkProcessBulkResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{
         *   succeeded: list<ProcessViewData>,
         *   failed: list<array{id: int, message: string}>
         * } $result
         */
        $result = $this->resource;

        return [
            'data' => WorkProcessResource::collection(
                $result['succeeded'],
            )->resolve($request),
            'meta' => [
                'succeeded' => count($result['succeeded']),
                'failed' => $result['failed'],
            ],
        ];
    }
}
