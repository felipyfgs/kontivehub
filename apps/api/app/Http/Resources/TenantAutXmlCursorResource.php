<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantAutXmlCursorData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantAutXmlCursorData */
final class TenantAutXmlCursorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cursor = $this->cursor;

        return [
            'id' => $cursor->id,
            'interested_root_cnpj' => $cursor->interested_root_cnpj,
            'query_cnpj' => $cursor->query_cnpj,
            'environment' => $cursor->environment,
            'channel' => $cursor->channel->value,
            'last_nsu' => $cursor->last_nsu,
            'max_nsu_seen' => $cursor->max_nsu_seen,
            'status' => $cursor->status->value,
            'last_cstat' => $cursor->last_cstat,
            'last_xmotivo' => $cursor->last_xmotivo,
            'consecutive_decode_failures' => $cursor->consecutive_decode_failures,
            'activated_at' => $cursor->activated_at?->toIso8601String(),
            'next_sync_at' => $cursor->next_sync_at?->toIso8601String(),
            'last_success_at' => $cursor->last_success_at?->toIso8601String(),
            'last_heartbeat_at' => $cursor->last_heartbeat_at?->toIso8601String(),
            'external_consumer_status' => $cursor->external_consumer_status,
            'circuit_open' => $this->circuitOpen,
            'backoff' => $this->backoff,
            'circuit_breaker_open' => $this->circuitBreakerOpen,
        ];
    }
}
