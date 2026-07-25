<?php

namespace App\Services\Clients;

use App\DTO\Cnpj\AddressData;
use App\DTO\Cnpj\ClientRegistrationData;
use App\DTO\Cnpj\CnpjRegistrationLookupResult;
use App\DTO\Cnpj\EstablishmentRegistrationData;
use App\Enums\RegistrationStatus;

/**
 * Merge fail-soft: campos do overlay prevalecem quando não nulos; gaps vêm da base.
 */
final class RegistrationLookupMerger
{
    public function merge(
        CnpjRegistrationLookupResult $base,
        CnpjRegistrationLookupResult $overlay,
        string $primarySource,
    ): CnpjRegistrationLookupResult {
        $baseClient = $base->client;
        $overClient = $overlay->client;
        $baseEst = $base->establishment;
        $overEst = $overlay->establishment;

        $sources = array_values(array_unique(array_merge(
            $base->sourcesUsed !== [] ? $base->sourcesUsed : [$base->source],
            $overlay->sourcesUsed !== [] ? $overlay->sourcesUsed : [$overlay->source],
        )));

        $client = new ClientRegistrationData(
            rootCnpj: $this->preferString($overClient->rootCnpj, $baseClient->rootCnpj) ?? $baseClient->rootCnpj,
            legalName: $this->preferString($overClient->legalName, $baseClient->legalName) ?? $baseClient->legalName,
            legalNatureCode: $this->preferString($overClient->legalNatureCode, $baseClient->legalNatureCode),
            legalNatureName: $this->preferString($overClient->legalNatureName, $baseClient->legalNatureName),
            companySizeCode: $this->preferString($overClient->companySizeCode, $baseClient->companySizeCode),
            companySizeName: $this->preferString($overClient->companySizeName, $baseClient->companySizeName),
            capitalSocial: $this->preferString($overClient->capitalSocial, $baseClient->capitalSocial),
            responsibleQualificationCode: $this->preferString(
                $overClient->responsibleQualificationCode,
                $baseClient->responsibleQualificationCode,
            ),
            responsibleQualificationName: $this->preferString(
                $overClient->responsibleQualificationName,
                $baseClient->responsibleQualificationName,
            ),
        );

        $status = $overEst->registrationStatus !== RegistrationStatus::Unknown
            ? $overEst->registrationStatus
            : $baseEst->registrationStatus;

        $establishment = new EstablishmentRegistrationData(
            cnpj: $this->preferString($overEst->cnpj, $baseEst->cnpj) ?? $baseEst->cnpj,
            tradeName: $this->preferString($overEst->tradeName, $baseEst->tradeName),
            isMatrix: $overEst->isMatrix || $baseEst->isMatrix,
            registrationStatus: $status,
            registrationStatusAt: $this->preferString($overEst->registrationStatusAt, $baseEst->registrationStatusAt),
            registrationStatusReason: $this->preferString($overEst->registrationStatusReason, $baseEst->registrationStatusReason),
            activityStartedAt: $this->preferString($overEst->activityStartedAt, $baseEst->activityStartedAt),
            mainCnaeCode: $this->preferString($overEst->mainCnaeCode, $baseEst->mainCnaeCode),
            mainCnaeName: $this->preferString($overEst->mainCnaeName, $baseEst->mainCnaeName),
            address: $this->mergeAddress($baseEst->address, $overEst->address),
            publicEmail: $this->preferString($overEst->publicEmail, $baseEst->publicEmail),
            publicPhone: $this->preferString($overEst->publicPhone, $baseEst->publicPhone),
            sourceUpdatedAt: $this->preferString($overEst->sourceUpdatedAt, $baseEst->sourceUpdatedAt),
            secondaryCnaes: $overEst->secondaryCnaes !== [] ? $overEst->secondaryCnaes : $baseEst->secondaryCnaes,
            stateRegistrations: $overEst->stateRegistrations !== [] ? $overEst->stateRegistrations : $baseEst->stateRegistrations,
            shareholders: $overEst->shareholders !== [] ? $overEst->shareholders : $baseEst->shareholders,
            publicPhoneSecondary: $this->preferString($overEst->publicPhoneSecondary, $baseEst->publicPhoneSecondary),
            publicFax: $this->preferString($overEst->publicFax, $baseEst->publicFax),
            specialSituation: $this->preferString($overEst->specialSituation, $baseEst->specialSituation),
            specialSituationAt: $this->preferString($overEst->specialSituationAt, $baseEst->specialSituationAt),
            simplesOptant: $overEst->simplesOptant ?? $baseEst->simplesOptant,
            meiOptant: $overEst->meiOptant ?? $baseEst->meiOptant,
        );

        return new CnpjRegistrationLookupResult(
            source: $primarySource,
            sourceUpdatedAt: $this->preferString($overlay->sourceUpdatedAt, $base->sourceUpdatedAt),
            client: $client,
            establishment: $establishment,
            sourcesUsed: $sources,
        );
    }

    private function mergeAddress(AddressData $base, AddressData $overlay): AddressData
    {
        return new AddressData(
            postalCode: $this->preferString($overlay->postalCode, $base->postalCode),
            streetType: $this->preferString($overlay->streetType, $base->streetType),
            street: $this->preferString($overlay->street, $base->street),
            number: $this->preferString($overlay->number, $base->number),
            complement: $this->preferString($overlay->complement, $base->complement),
            district: $this->preferString($overlay->district, $base->district),
            city: $this->preferString($overlay->city, $base->city),
            cityIbgeCode: $this->preferString($overlay->cityIbgeCode, $base->cityIbgeCode),
            state: $this->preferString($overlay->state, $base->state),
            country: $this->preferString($overlay->country, $base->country),
        );
    }

    private function preferString(?string $preferred, ?string $fallback): ?string
    {
        if ($preferred !== null && trim($preferred) !== '') {
            return $preferred;
        }

        return $fallback;
    }
}
