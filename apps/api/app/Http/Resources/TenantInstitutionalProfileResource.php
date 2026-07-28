<?php

namespace App\Http\Resources;

use App\Models\TenantInstitutionalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantInstitutionalProfile */
final class TenantInstitutionalProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantInstitutionalProfile $profile */
        $profile = $this->resource;

        return [
            'id' => $profile->id,
            'cnpj' => $profile->cnpj,
            'legal_name' => $profile->legal_name,
            'institutional_email' => $profile->institutional_email,
            'institutional_phone' => $profile->institutional_phone,
            'is_complete' => $profile->isComplete(),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }
}
