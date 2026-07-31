<?php

namespace App\Http\Resources;

use App\DTO\Tenant\SettingsOverviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SettingsOverviewData */
final class TenantSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SettingsOverviewData $settings */
        $settings = $this->resource;

        return [
            'profile' => TenantInstitutionalProfileResource::make(
                $settings->profile,
            )->resolve($request),
            'consent' => TenantConsentStatusResource::make(
                $settings->consent,
            )->resolve($request),
            'certificate' => $settings->certificate->certificate === null
                ? null
                : TenantCredentialResource::make(
                    $settings->certificate->certificate,
                )->resolve($request),
            'purpose_links' => TenantCredentialPurposeLinkResource::collection(
                $settings->certificate->purposeLinks,
            )->resolve($request),
            'alerts' => $settings->certificate->alerts,
        ];
    }
}
