<?php

namespace App\Http\Resources;

use App\DTO\Tenant\CertificateData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CertificateData */
final class TenantCertificateStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CertificateData $status */
        $status = $this->resource;

        return [
            'certificate' => $status->certificate === null
                ? null
                : TenantCredentialResource::make($status->certificate)->resolve($request),
            'alerts' => $status->alerts,
        ];
    }
}
