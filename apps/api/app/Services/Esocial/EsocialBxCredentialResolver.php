<?php

namespace App\Services\Esocial;

use App\Enums\CredentialStatus;
use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Office;
use App\Services\Certificates\CredentialService;

final class EsocialBxCredentialResolver
{
    public function __construct(private readonly CredentialService $credentials) {}

    public function active(Office $office, Client $client): ?ClientCredential
    {
        if ((int) $client->office_id !== (int) $office->id) {
            return null;
        }

        return ClientCredential::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('status', CredentialStatus::Active)
            ->latest('id')
            ->first();
    }

    /** @return array{pfx:string,password:string} */
    public function material(Office $office, Client $client): array
    {
        $credential = $this->active($office, $client);
        if ($credential === null) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CREDENTIAL_MISSING',
                'Cliente sem certificado A1 ativo para o eSocial BX.',
                blocked: true,
            );
        }
        $material = $this->credentials->loadPfxMaterial($credential);
        if ($material === null) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CREDENTIAL_UNUSABLE',
                'Certificado A1 expirado ou indisponível para o eSocial BX.',
                blocked: true,
            );
        }

        return $material;
    }
}
