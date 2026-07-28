<?php

namespace App\Http\Resources;

use App\Models\SerproDteCanaryRequest;
use App\Models\SerproOperationAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproDteCanaryRequest */
final class SerproDteCanaryTenantResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproDteCanaryRequest $canary */
        $canary = $this->resource;
        $payload = SerproDteCanaryRequestResource::make($canary)->resolve($request);
        $attempt = $canary->relationLoaded('attempt') ? $canary->attempt : null;

        $payload['fiscal_result'] = $attempt instanceof SerproOperationAttempt
            ? [
                'success' => $attempt->success,
                'http_status' => $attempt->http_status,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'business_status' => $attempt->business_status,
                'attempt_state' => $attempt->attempt_state?->value ?? (string) $attempt->attempt_state,
                'simulated' => (bool) $attempt->simulated,
                'dados' => $attempt->dados,
                'mensagens' => $attempt->mensagens,
                'finished_at' => $attempt->acknowledged_at?->toIso8601String()
                    ?? $attempt->updated_at?->toIso8601String(),
            ]
            : null;

        return $payload;
    }
}
