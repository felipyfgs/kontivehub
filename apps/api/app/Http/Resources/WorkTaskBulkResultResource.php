<?php

namespace App\Http\Resources;

use App\Models\WorkTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkTaskBulkResultResource extends JsonResource
{
    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{succeeded: int, failed: list<array{id: int, message: string}>}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{
         *   succeeded: list<WorkTask>,
         *   failed: list<array{id: int, message: string}>
         * } $result
         */
        $result = $this->resource;

        return [
            'data' => WorkTaskResource::collection(
                $result['succeeded'],
            )->resolve($request),
            'meta' => [
                'succeeded' => count($result['succeeded']),
                'failed' => $result['failed'],
            ],
        ];
    }
}
