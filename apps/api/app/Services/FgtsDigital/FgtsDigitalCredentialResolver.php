<?php

namespace App\Services\FgtsDigital;

use App\Enums\CredentialStatus;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalRepresentationStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FgtsDigitalRepresentation;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Certificates\TenantCredentialService;

final class FgtsDigitalCredentialResolver
{
    public function __construct(
        private readonly CredentialService $clientCredentials,
        private readonly TenantCredentialService $tenantCredentials,
    ) {}

    /**
     * @return array{source:FgtsDigitalCredentialSource,fingerprint:string,profile_type:string,pfx:?string,password:?string,representation_id:?int}|null
     */
    public function resolve(Tenant $tenant, Client $client, bool $includeMaterial = true): ?array
    {
        if ((string) config('fgts_digital.driver') === 'fixture') {
            return [
                'source' => FgtsDigitalCredentialSource::Client,
                'fingerprint' => hash('sha256', 'fixture'),
                'profile_type' => 'EMPREGADOR',
                'pfx' => null,
                'password' => null,
                'representation_id' => null,
            ];
        }

        $direct = ClientCredential::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('status', CredentialStatus::Active->value)
            ->first();
        if ($direct !== null && $direct->valid_to?->isFuture()) {
            $material = $includeMaterial ? $this->clientCredentials->loadPfxMaterial($direct) : null;
            if (! $includeMaterial || $material !== null) {
                return [
                    'source' => FgtsDigitalCredentialSource::Client,
                    'fingerprint' => (string) $direct->fingerprint_sha256,
                    'profile_type' => 'EMPREGADOR',
                    'pfx' => $material['pfx'] ?? null,
                    'password' => $material['password'] ?? null,
                    'representation_id' => null,
                ];
            }
        }

        if (! (bool) config('fgts_digital.tenant_credential_enabled', false)) {
            return null;
        }

        $representation = FgtsDigitalRepresentation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('credential_source', FgtsDigitalCredentialSource::Tenant->value)
            ->where('target_identifier_hash', self::identifierHash((string) $client->root_cnpj))
            ->where('status', FgtsDigitalRepresentationStatus::Active->value)
            ->orderByDesc('id')
            ->first();
        if ($representation === null || ! $representation->isUsable()) {
            return null;
        }

        $credential = TenantCredential::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', CredentialStatus::Active->value)
            ->first();
        if ($credential === null
            || ($representation->tenant_credential_id !== null
                && (int) $representation->tenant_credential_id !== (int) $credential->id)
        ) {
            return null;
        }

        $material = $includeMaterial ? $this->tenantCredentials->loadPfxMaterial($credential) : null;
        if ($includeMaterial && $material === null) {
            return null;
        }

        return [
            'source' => FgtsDigitalCredentialSource::Tenant,
            'fingerprint' => (string) $credential->fingerprint_sha256,
            'profile_type' => (string) $representation->profile_type,
            'pfx' => $material['pfx'] ?? null,
            'password' => $material['password'] ?? null,
            'representation_id' => (int) $representation->id,
        ];
    }

    public static function identifierHash(string $identifier): string
    {
        return hash_hmac('sha256', preg_replace('/\D+/', '', $identifier) ?? '', (string) config('app.key'));
    }
}
