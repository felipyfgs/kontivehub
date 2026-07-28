<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantProxyPowerSyncData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class SyncTenantProxyPowersRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'client_id' => ['required', 'integer'],
            'power_code' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function toDto(): TenantProxyPowerSyncData
    {
        return new TenantProxyPowerSyncData(
            environment: $this->environment(),
            clientId: (int) $this->validated('client_id'),
            actorUserId: $this->actor()->id,
        );
    }
}
