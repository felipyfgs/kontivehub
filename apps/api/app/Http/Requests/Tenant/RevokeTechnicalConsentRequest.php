<?php

namespace App\Http\Requests\Tenant;

final class RevokeTechnicalConsentRequest extends SettingsMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
