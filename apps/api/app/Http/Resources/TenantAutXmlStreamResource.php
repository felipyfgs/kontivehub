<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantAutXmlStreamData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantAutXmlStreamData */
final class TenantAutXmlStreamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'stream_ready' => $this->streamReady,
            'stream_reason' => $this->streamReason,
            'quiet_hours' => $this->quietHours,
            'activated_at' => $this->activatedAt,
            'ready_at' => $this->readyAt,
        ];
    }
}
