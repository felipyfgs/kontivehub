<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantTechnicalConsentGrantData;

final class GrantTenantTechnicalConsentRequest extends TenantSettingsMutationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
            'version_code' => ['sometimes', 'string', 'max:40'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function toDto(): TenantTechnicalConsentGrantData
    {
        return new TenantTechnicalConsentGrantData(
            versionCode: $this->validated('version_code'),
            actorUserId: $this->actor()->id,
        );
    }
}
