<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantCertificateUploadData;

final class UploadTenantCertificateRequest extends TenantSettingsMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent_accepted' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function toDto(): TenantCertificateUploadData
    {
        return new TenantCertificateUploadData(
            filePath: $this->file('pfx')?->getRealPath() ?: '',
            password: (string) $this->validated('password'),
            actorUserId: $this->actor()->id,
        );
    }
}
