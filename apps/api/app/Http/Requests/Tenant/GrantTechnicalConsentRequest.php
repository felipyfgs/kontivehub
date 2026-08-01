<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TechnicalConsentGrantData;

final class GrantTechnicalConsentRequest extends SettingsMutationRequest
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

    public function toDto(): TechnicalConsentGrantData
    {
        return new TechnicalConsentGrantData(
            versionCode: $this->validated('version_code'),
            actorUserId: $this->actor()->id,
        );
    }
}
