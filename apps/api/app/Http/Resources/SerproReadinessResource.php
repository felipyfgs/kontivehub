<?php

namespace App\Http\Resources;

use App\Models\SerproReadinessRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproReadinessRun|array<string, mixed> */
final class SerproReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        /** @var SerproReadinessRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'scope' => $run->scope->value,
            'environment' => $run->environment->value,
            'tenant_id' => $run->tenant_id,
            'client_id' => $run->client_id,
            'operation_key' => $run->operation_key,
            'highest_gate' => $run->highest_gate?->value,
            'result' => $run->result,
            'live_evidence' => $run->live_evidence,
            'trigger' => $run->trigger,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
            'summary' => $run->summary,
            'evidences' => $run->evidences->map->toSanitizedArray()->all(),
        ];
    }
}
