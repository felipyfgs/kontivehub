<?php

namespace App\Services\Esocial;

use App\Enums\CredentialStatus;
use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Tenant;
use App\Services\Certificates\CredentialService;

final class EsocialBxCredentialResolver
{
    public function __construct(private readonly CredentialService $credentials) {}

    public function active(Tenant $tenant, Client $client): ?ClientCredential
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            return null;
        }

        return ClientCredential::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('status', CredentialStatus::Active)
            ->latest('id')
            ->first();
    }

    /** @return array{pfx:string,password:string} */
    public function material(Tenant $tenant, Client $client): array
    {
        $credential = $this->active($tenant, $client);
        if ($credential === null) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CREDENTIAL_MISSING',
                'Cliente sem certificado ativo para o eSocial BX.',
                blocked: true,
            );
        }
        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CREDENTIAL_UNUSABLE',
                'certificado expirado ou indisponível para o eSocial BX.',
                blocked: true,
            );
        }

        return $material;
    }
}
