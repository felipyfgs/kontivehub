<?php

namespace App\Http\Resources;

use App\DTO\Tenant\ConsentStatusData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConsentStatusData */
final class TenantConsentStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ConsentStatusData $status */
        $status = $this->resource;

        return [
            'version_code' => $status->versionCode,
            'purposes_presented' => $status->purposesPresented,
            'active_consent' => $status->activeConsent === null
                ? null
                : TenantTechnicalConsentResource::make($status->activeConsent)->resolve($request),
            'requires_consent' => $status->requiresConsent(),
        ];
    }
}
