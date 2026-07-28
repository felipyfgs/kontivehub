<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCustomFieldUpdateData;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Validation\ValidationException;

final class UpdateClientCustomFieldRequest extends AuthenticatedRequest
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
            'label' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'value' => ['nullable', 'string', 'max:10000'],
            'tenant_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'type' => ['prohibited'],
            'vault_object_id' => ['prohibited'],
        ];
    }

    public function toDto(): ClientCustomFieldUpdateData
    {
        return new ClientCustomFieldUpdateData($this->validated());
    }
}
