<?php

namespace App\Actions\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalRepresentationData;
use App\DTO\FgtsDigital\FgtsDigitalSessionImportData;
use App\Enums\CredentialStatus;
use App\Enums\FgtsDigitalCredentialSource;
use App\Enums\FgtsDigitalRepresentationStatus;
use App\Models\FgtsDigitalRepresentation;
use App\Models\FgtsDigitalSession;
use App\Models\TenantCredential;
use App\Models\User;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalCredentialResolver;
use App\Services\FgtsDigital\FgtsDigitalQuery;
use App\Services\FgtsDigital\FgtsDigitalSessionStore;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class ManageFgtsDigitalCredentialAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsDigitalQuery $query,
        private FgtsDigitalCredentialResolver $credentials,
        private FgtsDigitalSessionStore $sessions,
    ) {}

    public function importSession(
        FgtsDigitalSessionImportData $data,
    ): FgtsDigitalSession {
        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);
        $credential = $this->credentials->resolve(
            $tenant,
            $client,
            includeMaterial: false,
        );
        if ($credential === null) {
            throw new FgtsDigitalException(
                'Credencial/procuração não está pronta.',
                'FGTS_DIGITAL_CREDENTIAL_MISSING',
                422,
            );
        }

        return $this->sessions->store(
            (int) $tenant->id,
            (int) $client->id,
            $credential['source'],
            $credential['fingerprint'],
            $credential['profile_type'],
            FgtsDigitalCredentialResolver::identifierHash(
                (string) $client->root_cnpj,
            ),
            $data->storageState,
            $credential['representation_id'],
        );
    }

    public function storeRepresentation(
        User $actor,
        FgtsDigitalRepresentationData $data,
    ): FgtsDigitalRepresentation {
        if (! (bool) config('fgts_digital.tenant_credential_enabled', false)) {
            throw new FgtsDigitalException(
                'Uso do certificado do escritório está desabilitado.',
                'FGTS_DIGITAL_TENANT_CREDENTIAL_DISABLED',
                403,
            );
        }

        $tenant = $this->currentTenant->tenant();
        $client = $this->query->client($data->clientId);

        return DB::transaction(function () use (
            $tenant,
            $client,
            $actor,
            $data,
        ): FgtsDigitalRepresentation {
            $credential = TenantCredential::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', CredentialStatus::Active->value)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($credential === null) {
                throw new FgtsDigitalException(
                    'certificado do escritório não está ativo.',
                    'FGTS_DIGITAL_TENANT_CREDENTIAL_MISSING',
                    422,
                );
            }

            return FgtsDigitalRepresentation::query()->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'tenant_credential_id' => $credential->id,
                'credential_source' => FgtsDigitalCredentialSource::Tenant,
                'profile_type' => $data->profileType,
                'target_identifier_hash' => FgtsDigitalCredentialResolver::identifierHash(
                    (string) $client->root_cnpj,
                ),
                'status' => FgtsDigitalRepresentationStatus::Active,
                'valid_from' => now(),
                'valid_to' => $data->validTo,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'metadata' => [
                    'source' => 'EXPLICIT_ADMIN_CONFIRMATION',
                ],
            ]);
        });
    }
}
