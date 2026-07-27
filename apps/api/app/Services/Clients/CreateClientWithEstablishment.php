<?php

namespace App\Services\Clients;

use App\Contracts\CnpjRegistrationLookup;
use App\Contracts\SecureObjectStore;
use App\Domain\Cnpj;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientCustomField;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Usage\CommercialEntitlementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cria/atualiza o agregado canônico Cliente (raiz) + Estabelecimento (CNPJ completo).
 *
 * - Um Cliente por (tenant_id, root_cnpj).
 * - Vários Estabelecimentos por Cliente; no máximo uma matriz ativa.
 */
final class CreateClientWithEstablishment
{
    public function __construct(
        private readonly CnpjRegistrationLookup $lookup,
        private readonly AuditLogger $audit,
        private readonly SecureObjectStore $secureObjectStore,
        private readonly CommercialEntitlementService $commercialEntitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{client: Client, establishment: Establishment, contact: ClientContact|null, custom_fields: list<ClientCustomField>}
     *
     * @throws DuplicateEstablishmentException
     * @throws ValidationException
     * @throws \InvalidArgumentException
     */
    public function handle(int $tenantId, array $payload): array
    {
        $cnpj = Cnpj::parse((string) $payload['cnpj']);
        $root = $cnpj->root();

        $newVaultObjects = [];

        try {
            return DB::transaction(function () use ($tenantId, $payload, $cnpj, $root, &$newVaultObjects): array {
                // Unicidade de negócio: CNPJ completo (14), não a raiz.
                $duplicateEstablishment = Establishment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('cnpj', $cnpj->value())
                    ->lockForUpdate()
                    ->first();

                if ($duplicateEstablishment !== null) {
                    $existingClient = Client::query()
                        ->where('tenant_id', $tenantId)
                        ->where('id', $duplicateEstablishment->client_id)
                        ->first();

                    if ($existingClient !== null) {
                        throw new DuplicateEstablishmentException($existingClient);
                    }

                    throw new DuplicateEstablishmentException(null);
                }

                $cached = $this->lookup->getCached($cnpj->value());
                $source = match ($cached?->source) {
                    SerproConsultaCnpjLookup::SOURCE => RegistrationSource::SerproConsulta,
                    CnpjWsRegistrationLookup::SOURCE => RegistrationSource::CnpjWs,
                    default => $cached !== null ? RegistrationSource::CnpjWs : RegistrationSource::Manual,
                };
                $refreshedAt = null;
                if ($cached?->sourceUpdatedAt !== null) {
                    try {
                        $refreshedAt = Carbon::parse($cached->sourceUpdatedAt);
                    } catch (Throwable) {
                        $refreshedAt = now();
                    }
                } elseif ($cached !== null) {
                    $refreshedAt = now();
                }

                $status = $this->resolveStatus($payload);

                // Situação externa conhecida e não ativa → captura sempre desabilitada no create
                // (payload capture_enabled=true é ignorado; reabilitação excepcional só via PATCH com motivo).
                // UNKNOWN (manual/sem consulta) e ACTIVE seguem o payload ou o default.
                $knownNonActive = ! $status->isActive() && $status !== RegistrationStatus::Unknown;
                if ($knownNonActive) {
                    $captureEnabled = false;
                } else {
                    $captureEnabled = array_key_exists('capture_enabled', $payload)
                        ? (bool) $payload['capture_enabled']
                        : true;
                }

                $client = $this->resolveRootClient($tenantId, $root);
                $createdNewClient = $client === null;

                if ($createdNewClient) {
                    // Franquia comercial de carteira — só no novo Cliente-raiz (não em filial/estabelecimento).
                    $this->commercialEntitlements->assertCanCreateClient($tenantId);

                    $client = Client::query()->create([
                        'tenant_id' => $tenantId,
                        'legal_name' => (string) $payload['legal_name'],
                        'display_name' => $payload['display_name'] ?? null,
                        'root_cnpj' => $root,
                        'legal_nature_code' => $payload['legal_nature_code'] ?? null,
                        'legal_nature_name' => $payload['legal_nature_name'] ?? null,
                        'company_size_code' => $payload['company_size_code'] ?? null,
                        'company_size_name' => $payload['company_size_name'] ?? null,
                        'capital_social' => $payload['capital_social'] ?? $cached?->client->capitalSocial,
                        'responsible_qualification_code' => $payload['responsible_qualification_code']
                            ?? $cached?->client->responsibleQualificationCode,
                        'responsible_qualification_name' => $payload['responsible_qualification_name']
                            ?? $cached?->client->responsibleQualificationName,
                        'tax_regime' => $payload['tax_regime'] ?? null,
                        'notes' => $payload['notes'] ?? null,
                        'is_active' => $payload['is_active'] ?? true,
                        'inactive_reason' => $payload['inactive_reason'] ?? null,
                        'registration_source' => $source,
                        'registration_refreshed_at' => $refreshedAt,
                    ]);
                }

                $hasMatrixEstablishment = Establishment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('client_id', $client->id)
                    ->where('is_headquarters', true)
                    ->lockForUpdate()
                    ->exists();

                // Novo estabelecimento é matriz só se ainda não houver matriz ativa no Cliente.
                $wantsMatrix = (bool) ($payload['is_headquarters'] ?? ! $hasMatrixEstablishment);
                $isMatrixEstablishment = $wantsMatrix && ! $hasMatrixEstablishment;

                $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

                $secondaryCnaes = $payload['secondary_cnaes']
                    ?? ($cached !== null
                        ? array_map(static fn ($item) => $item->toArray(), $cached->establishment->secondaryCnaes)
                        : null);
                $stateRegistrations = $payload['state_registrations']
                    ?? ($cached !== null
                        ? array_map(static fn ($item) => $item->toArray(), $cached->establishment->stateRegistrations)
                        : null);
                $shareholders = $payload['shareholders']
                    ?? ($cached !== null
                        ? array_map(static fn ($item) => $item->toArray(), $cached->establishment->shareholders)
                        : null);

                $establishment = Establishment::query()->create([
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'cnpj' => $cnpj->value(),
                    'trade_name' => $payload['trade_name'] ?? null,
                    'is_headquarters' => $isMatrixEstablishment,
                    'is_active' => $payload['establishment_is_active'] ?? true,
                    'registration_status' => $status,
                    'registration_status_at' => $payload['registration_status_at'] ?? null,
                    'registration_status_reason' => $payload['registration_status_reason'] ?? null,
                    'special_situation' => $payload['special_situation'] ?? $cached?->establishment->specialSituation,
                    'special_situation_at' => $payload['special_situation_at'] ?? $cached?->establishment->specialSituationAt,
                    'activity_started_at' => $payload['activity_started_at'] ?? null,
                    'main_cnae_code' => $payload['main_cnae_code'] ?? null,
                    'main_cnae_name' => $payload['main_cnae_name'] ?? null,
                    'secondary_cnaes' => is_array($secondaryCnaes) ? $secondaryCnaes : null,
                    'state_registrations' => is_array($stateRegistrations) ? $stateRegistrations : null,
                    'shareholders' => is_array($shareholders) ? $shareholders : null,
                    'address_postal_code' => $address['postal_code'] ?? $payload['address_postal_code'] ?? null,
                    'address_street_type' => $address['street_type'] ?? $payload['address_street_type'] ?? null,
                    'address_street' => $address['street'] ?? $payload['address_street'] ?? null,
                    'address_number' => $address['number'] ?? $payload['address_number'] ?? null,
                    'address_complement' => $address['complement'] ?? $payload['address_complement'] ?? null,
                    'address_district' => $address['district'] ?? $payload['address_district'] ?? null,
                    'address_city' => $address['city'] ?? $payload['address_city'] ?? null,
                    'address_city_ibge_code' => $address['city_ibge_code'] ?? $payload['address_city_ibge_code'] ?? null,
                    'address_state' => $address['state'] ?? $payload['address_state'] ?? null,
                    'address_country' => $address['country'] ?? $payload['address_country'] ?? 'BR',
                    'public_email' => $payload['public_email'] ?? null,
                    'public_phone' => $payload['public_phone'] ?? null,
                    'public_phone_secondary' => $payload['public_phone_secondary']
                        ?? $cached?->establishment->publicPhoneSecondary,
                    'public_fax' => $payload['public_fax'] ?? $cached?->establishment->publicFax,
                    'simples_optant' => array_key_exists('simples_optant', $payload)
                        ? $payload['simples_optant']
                        : $cached?->establishment->simplesOptant,
                    'mei_optant' => array_key_exists('mei_optant', $payload)
                        ? $payload['mei_optant']
                        : $cached?->establishment->meiOptant,
                    'capture_enabled' => $captureEnabled,
                    'registration_source' => $source,
                    'registration_refreshed_at' => $refreshedAt,
                ]);

                $contact = null;
                $customFields = [];
                if ($createdNewClient) {
                    $contact = $this->createInitialContact($tenantId, $client, $payload);
                    $customFields = $this->createCustomFields(
                        $tenantId,
                        $client,
                        $payload,
                        $newVaultObjects,
                    );

                    $this->audit->record('client.create', 'SUCCESS', $client, [
                        'root_cnpj' => $client->root_cnpj,
                        'fields' => ['legal_name', 'root_cnpj', 'registration_source'],
                        'registration_source' => $source->value,
                        'establishment_id' => $establishment->id,
                    ]);
                } else {
                    $this->audit->record('client.establishment_attach', 'SUCCESS', $client, [
                        'root_cnpj' => $client->root_cnpj,
                        'establishment_id' => $establishment->id,
                    ]);
                }

                $this->audit->record('establishment.create', 'SUCCESS', $establishment, [
                    'client_id' => $client->id,
                    'fields' => ['cnpj', 'is_headquarters', 'registration_status', 'capture_enabled'],
                    'is_headquarters' => $establishment->is_headquarters,
                    'registration_status' => $status->value,
                    'capture_enabled' => $captureEnabled,
                ]);

                if ($contact !== null) {
                    $this->audit->record('client_contact.create', 'SUCCESS', $contact, [
                        'client_id' => $client->id,
                        'fields' => ['name', 'is_primary', 'receives_alerts'],
                        'is_primary' => $contact->is_primary,
                    ]);
                }

                return [
                    'client' => $client->fresh() ?? $client,
                    'establishment' => $establishment->fresh() ?? $establishment,
                    'contact' => $contact?->fresh() ?? $contact,
                    'custom_fields' => $customFields,
                ];
            });
        } catch (Throwable $exception) {
            foreach ($newVaultObjects as $objectId) {
                try {
                    $this->secureObjectStore->delete($objectId);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /**
     * Localiza o Cliente do tenant pela raiz do CNPJ.
     */
    private function resolveRootClient(int $tenantId, string $root): ?Client
    {
        return Client::query()
            ->where('tenant_id', $tenantId)
            ->where('root_cnpj', $root)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createInitialContact(int $tenantId, Client $client, array $payload): ?ClientContact
    {
        $data = $payload['initial_contact'] ?? null;

        if (! is_array($data) || $data === []) {
            return null;
        }

        return ClientContact::query()->create([
            'tenant_id' => $tenantId,
            'client_id' => $client->id,
            'name' => (string) $data['name'],
            'role' => $data['role'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_whatsapp' => $data['is_whatsapp'] ?? false,
            'is_primary' => $data['is_primary'] ?? true,
            'receives_alerts' => $data['receives_alerts'] ?? false,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $newVaultObjects
     * @return list<ClientCustomField>
     */
    private function createCustomFields(
        int $tenantId,
        Client $client,
        array $payload,
        array &$newVaultObjects,
    ): array {
        $created = [];

        foreach ($payload['custom_fields'] ?? [] as $data) {
            if (! is_array($data)) {
                continue;
            }

            $fieldKey = (string) Str::ulid();
            $type = (string) $data['type'];
            $value = (string) ($data['value'] ?? '');
            $objectId = null;

            if ($type === 'SECRET' && $value !== '') {
                $objectId = $this->secureObjectStore->put($value, [
                    'tenant_id' => $tenantId,
                    'client_id' => $client->id,
                    'field_key' => $fieldKey,
                ]);
                $newVaultObjects[] = $objectId;
            }

            $field = ClientCustomField::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $client->id,
                'field_key' => $fieldKey,
                'label' => (string) $data['label'],
                'type' => $type,
                'value_text' => $type === 'TEXT' ? $value : null,
                'vault_object_id' => $objectId,
            ]);

            $this->audit->record('client_custom_field.create', 'SUCCESS', $field, [
                'client_id' => $client->id,
                'type' => $type,
                'has_value' => $value !== '',
            ]);
            $created[] = $field;
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveStatus(array $payload): RegistrationStatus
    {
        if (! isset($payload['registration_status']) || $payload['registration_status'] === null || $payload['registration_status'] === '') {
            return RegistrationStatus::Unknown;
        }

        $raw = (string) $payload['registration_status'];

        try {
            return RegistrationStatus::from($raw);
        } catch (\ValueError) {
            return RegistrationStatus::fromExternal($raw);
        }
    }
}
