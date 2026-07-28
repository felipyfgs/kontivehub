<?php

namespace App\Http\Resources;

use App\Models\TenantTechnicalConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantTechnicalConsent */
final class TenantTechnicalConsentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantTechnicalConsent $consent */
        $consent = $this->resource;

        return [
            'id' => $consent->id,
            'version_code' => $consent->version_code,
            'purposes_presented' => $consent->purposes_presented,
            'consented_at' => $consent->consented_at?->toIso8601String(),
            'revoked_at' => $consent->revoked_at?->toIso8601String(),
            'payload_sha256' => $consent->payload_sha256,
            'active' => $consent->isActive(),
        ];
    }
}
