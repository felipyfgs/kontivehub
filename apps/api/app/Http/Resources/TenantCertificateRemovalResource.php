<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantCertificateData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantCertificateData */
final class TenantCertificateRemovalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantCertificateData $status */
        $status = $this->resource;

        return [
            'certificate' => $status->certificate === null
                ? null
                : TenantCredentialResource::make($status->certificate)->resolve($request),
            'removed' => $status->removed,
        ];
    }
}
