<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\PlatformOwnerUpdateData;
use App\Exceptions\PlatformOwnerApiException;
use App\Http\Requests\AuthenticatedRequest;

final class UpdatePlatformOwnerRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'default_tenant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function toDto(): PlatformOwnerUpdateData
    {
        $validated = $this->validated();
        if ($validated === []) {
            throw PlatformOwnerApiException::noFields();
        }

        return new PlatformOwnerUpdateData(
            hasName: array_key_exists('name', $validated),
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            hasEmail: array_key_exists('email', $validated),
            email: isset($validated['email']) ? (string) $validated['email'] : null,
            hasDefaultTenant: array_key_exists('default_tenant_id', $validated),
            defaultTenantId: isset($validated['default_tenant_id'])
                ? (int) $validated['default_tenant_id']
                : null,
        );
    }
}
