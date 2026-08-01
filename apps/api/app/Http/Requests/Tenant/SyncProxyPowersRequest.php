<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\ProxyPowerSyncData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class SyncProxyPowersRequest extends SerproAuthorizationMutationRequest
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

    public function toDto(): ProxyPowerSyncData
    {
        return new ProxyPowerSyncData(
            environment: $this->environment(),
            clientId: (int) $this->validated('client_id'),
            actorUserId: $this->actor()->id,
        );
    }
}
