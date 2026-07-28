<?php

namespace App\Http\Resources;

use App\Models\TenantCredentialPurposeLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantCredentialPurposeLink */
final class TenantCredentialPurposeLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantCredentialPurposeLink $link */
        $link = $this->resource;

        return [
            'id' => $link->id,
            'tenant_credential_id' => $link->tenant_credential_id,
            'purpose' => $link->purpose->value,
            'status' => $link->status->value,
            'linked_at' => $link->linked_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'active' => $link->isActive(),
        ];
    }
}
