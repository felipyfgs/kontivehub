<?php

namespace App\Services\FgtsDigital;

use App\Enums\CredentialStatus;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalRepresentationStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FgtsDigitalRepresentation;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Services\Certificates\CredentialService;
use App\Services\Certificates\OfficeCredentialService;

final class FgtsDigitalCredentialResolver
{
    public function __construct(
        private readonly CredentialService $clientCredentials,
        private readonly OfficeCredentialService $officeCredentials,
    ) {}

    /**
     * @return array{source:FgtsDigitalCredentialSource,fingerprint:string,profile_type:string,pfx:?string,password:?string,representation_id:?int}|null
     */
    public function resolve(Office $office, Client $client, bool $includeMaterial = true): ?array
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
            ->where('office_id', $office->id)
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

        if (! (bool) config('fgts_digital.office_credential_enabled', false)) {
            return null;
        }

        $representation = FgtsDigitalRepresentation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('credential_source', FgtsDigitalCredentialSource::Office->value)
            ->where('target_identifier_hash', self::identifierHash((string) $client->root_cnpj))
            ->where('status', FgtsDigitalRepresentationStatus::Active->value)
            ->orderByDesc('id')
            ->first();
        if ($representation === null || ! $representation->isUsable()) {
            return null;
        }

        $credential = OfficeCredential::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('purpose', OfficeCredentialPurpose::CanonicalECnpjA1->value)
            ->where('status', CredentialStatus::Active->value)
            ->first();
        if ($credential === null
            || ($representation->office_credential_id !== null
                && (int) $representation->office_credential_id !== (int) $credential->id)
        ) {
            return null;
        }

        $material = $includeMaterial ? $this->officeCredentials->loadPfxMaterial($credential) : null;
        if ($includeMaterial && $material === null) {
            return null;
        }

        return [
            'source' => FgtsDigitalCredentialSource::Office,
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
