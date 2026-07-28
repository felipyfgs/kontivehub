<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundTargetAdvanceResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundTargetAdvanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundTargetAdvanceResult $result */
        $result = $this->resource;

        return [
            'competence' => $result->competence,
            'target_at' => $result->targetAt->toIso8601String(),
            'due_at' => $result->dueAt->toIso8601String(),
            'updated_rows' => $result->updatedRows,
        ];
    }
}
