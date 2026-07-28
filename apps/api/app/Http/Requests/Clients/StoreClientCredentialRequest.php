<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCredentialActivationData;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientCredentialPolicy;
use App\Policies\ClientPolicy;
use Illuminate\Validation\ValidationException;

final class StoreClientCredentialRequest extends AuthenticatedRequest
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
            && app(ClientPolicy::class)->update($actor, $client)
            && app(ClientCredentialPolicy::class)->manage($actor, $client);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'pfx' => ['required', 'file', 'max:5120', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:300'],
            'tenant_id' => ['prohibited'],
            'client_id' => ['prohibited'],
            'status' => ['prohibited'],
            'vault_object_id' => ['prohibited'],
        ];
    }

    public function toDto(): ClientCredentialActivationData
    {
        $file = $this->file('pfx');
        $path = $file?->getRealPath();
        if ($file === null || ! is_string($path)) {
            throw ValidationException::withMessages([
                'pfx' => ['Arquivo PFX não encontrado.'],
            ]);
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            throw ValidationException::withMessages([
                'pfx' => ['Falha ao ler arquivo PFX.'],
            ]);
        }

        return new ClientCredentialActivationData(
            pfxBinary: $binary,
            password: (string) $this->validated('password'),
        );
    }
}
