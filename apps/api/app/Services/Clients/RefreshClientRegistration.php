<?php

namespace App\Services\Clients;

use App\Domain\Cnpj;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Models\Client;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Reconsulta RFB e faz merge nos campos cadastrais sem alterar dados internos do escritório.
 */
final class RefreshClientRegistration
{
    public function __construct(
        private readonly RegistrationLookupOrchestrator $lookup,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $lookupPayload  Snapshot confirmado pelo usuário (opcional). Sem payload, reconsulta a fonte.
     * @return array{client: Client, establishment: Establishment, lookup: array<string, mixed>}
     */
    public function handle(Client $client, ?array $lookupPayload = null): array
    {
        $establishment = Establishment::query()
            ->where('client_id', $client->id)
            ->where('office_id', $client->office_id)
            ->orderByDesc('is_matrix')
            ->orderBy('id')
            ->first();

        if ($establishment === null) {
            throw ValidationException::withMessages([
                'client' => ['Cliente sem estabelecimento para atualizar o cadastro RFB.'],
            ]);
        }

        try {
            $confirmedSnapshot = $lookupPayload !== null;
            $result = $confirmedSnapshot
                ? CnpjRegistrationLookupResult::fromArray($lookupPayload)
                : $this->lookup->findForClient($establishment->cnpj, $client);

            if ($confirmedSnapshot) {
                $snapshotCnpj = Cnpj::tryParse((string) $result->establishment->cnpj);
                $matrixCnpj = Cnpj::tryParse((string) $establishment->cnpj);
                if (
                    $snapshotCnpj === null
                    || $matrixCnpj === null
                    || $snapshotCnpj->value() !== $matrixCnpj->value()
                ) {
                    throw ValidationException::withMessages([
                        'lookup' => ['O CNPJ do snapshot confirmado não corresponde ao estabelecimento do cliente.'],
                    ]);
                }
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cnpj' => [$exception->getMessage()],
            ]);
        }

        $source = match ($result->source) {
            SerproConsultaCnpjLookup::SOURCE => RegistrationSource::SerproConsulta,
            default => RegistrationSource::CnpjWs,
        };

        $refreshedAt = now();
        if ($result->sourceUpdatedAt !== null) {
            try {
                $refreshedAt = Carbon::parse($result->sourceUpdatedAt);
            } catch (Throwable) {
                $refreshedAt = now();
            }
        }

        return DB::transaction(function () use ($client, $establishment, $result, $source, $refreshedAt, $confirmedSnapshot): array {
            $client->forceFill([
                'legal_name' => $result->client->legalName,
                'legal_nature_code' => $result->client->legalNatureCode,
                'legal_nature_name' => $result->client->legalNatureName,
                'company_size_code' => $result->client->companySizeCode,
                'company_size_name' => $result->client->companySizeName,
                'capital_social' => $result->client->capitalSocial,
                'responsible_qualification_code' => $result->client->responsibleQualificationCode,
                'responsible_qualification_name' => $result->client->responsibleQualificationName,
                'registration_source' => $source,
                'registration_refreshed_at' => $refreshedAt,
                // NÃO toca: display_name, tax_regime, notes, is_active, inactive_reason
            ])->save();

            $address = $result->establishment->address;
            $status = $result->establishment->registrationStatus;

            $establishment->forceFill([
                'trade_name' => $result->establishment->tradeName,
                'registration_status' => $status,
                'registration_status_at' => $result->establishment->registrationStatusAt,
                'registration_status_reason' => $result->establishment->registrationStatusReason,
                'special_situation' => $result->establishment->specialSituation,
                'special_situation_at' => $result->establishment->specialSituationAt,
                'activity_started_at' => $result->establishment->activityStartedAt,
                'main_cnae_code' => $result->establishment->mainCnaeCode,
                'main_cnae_name' => $result->establishment->mainCnaeName,
                'secondary_cnaes' => array_map(
                    static fn ($item) => $item->toArray(),
                    $result->establishment->secondaryCnaes,
                ),
                'state_registrations' => array_map(
                    static fn ($item) => $item->toArray(),
                    $result->establishment->stateRegistrations,
                ),
                'shareholders' => array_map(
                    static fn ($item) => $item->toArray(),
                    $result->establishment->shareholders,
                ),
                'address_postal_code' => $address->postalCode,
                'address_street_type' => $address->streetType,
                'address_street' => $address->street,
                'address_number' => $address->number,
                'address_complement' => $address->complement,
                'address_district' => $address->district,
                'address_city' => $address->city,
                'address_city_ibge_code' => $address->cityIbgeCode,
                'address_state' => $address->state,
                'address_country' => $address->country ?? 'BR',
                'public_email' => $result->establishment->publicEmail,
                'public_phone' => $result->establishment->publicPhone,
                'public_phone_secondary' => $result->establishment->publicPhoneSecondary,
                'public_fax' => $result->establishment->publicFax,
                'simples_optant' => $result->establishment->simplesOptant,
                'mei_optant' => $result->establishment->meiOptant,
                'registration_source' => $source,
                'registration_refreshed_at' => $refreshedAt,
            ])->save();

            // Situação conhecida não ativa → desabilita captura
            if (! $status->isActive() && $status !== RegistrationStatus::Unknown) {
                $establishment->forceFill(['capture_enabled' => false])->save();
            }

            $this->audit->record('client.registration_refresh', 'SUCCESS', $client, [
                'establishment_id' => $establishment->id,
                'registration_source' => $source->value,
                'sources_used' => $result->sourcesUsed,
                'cnpj' => Cnpj::parse($establishment->cnpj)->root(),
                'confirmed_snapshot' => $confirmedSnapshot,
            ]);

            return [
                'client' => $client->fresh() ?? $client,
                'establishment' => $establishment->fresh() ?? $establishment,
                'lookup' => $result->toArray(),
            ];
        });
    }
}
