<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientRegistrationRefreshData;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Validation\ValidationException;

final class RefreshClientRegistrationRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => ['tenant_id não é aceito; use o Tenant corrente.'],
            ]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(ClientPolicy::class)->update($actor, $client);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'lookup' => ['nullable', 'array'],
            'lookup.*' => ['nullable'],
            'lookup.*.*' => ['nullable'],
            'lookup.*.*.*' => ['nullable'],
            'lookup.*.*.*.*' => ['nullable'],
        ];
    }

    public function toDto(): ClientRegistrationRefreshData
    {
        $lookup = $this->validated('lookup');

        return new ClientRegistrationRefreshData(
            lookup: is_array($lookup) ? $lookup : null,
        );
    }
}
