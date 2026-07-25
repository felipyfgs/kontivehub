<?php

namespace App\DTO\Cnpj;

use App\Enums\RegistrationStatus;

final class EstablishmentRegistrationData
{
    /**
     * @param  list<CnaeData>  $secondaryCnaes
     * @param  list<StateRegistrationData>  $stateRegistrations
     * @param  list<ShareholderData>  $shareholders
     */
    public function __construct(
        public readonly string $cnpj,
        public readonly ?string $tradeName,
        public readonly bool $isMatrix,
        public readonly RegistrationStatus $registrationStatus,
        public readonly ?string $registrationStatusAt,
        public readonly ?string $registrationStatusReason,
        public readonly ?string $activityStartedAt,
        public readonly ?string $mainCnaeCode,
        public readonly ?string $mainCnaeName,
        public readonly AddressData $address,
        public readonly ?string $publicEmail,
        public readonly ?string $publicPhone,
        public readonly ?string $sourceUpdatedAt,
        public readonly array $secondaryCnaes = [],
        public readonly array $stateRegistrations = [],
        public readonly array $shareholders = [],
        public readonly ?string $publicPhoneSecondary = null,
        public readonly ?string $publicFax = null,
        public readonly ?string $specialSituation = null,
        public readonly ?string $specialSituationAt = null,
        public readonly ?bool $simplesOptant = null,
        public readonly ?bool $meiOptant = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cnpj' => $this->cnpj,
            'trade_name' => $this->tradeName,
            'is_matrix' => $this->isMatrix,
            'registration_status' => $this->registrationStatus->value,
            'registration_status_at' => $this->registrationStatusAt,
            'registration_status_reason' => $this->registrationStatusReason,
            'activity_started_at' => $this->activityStartedAt,
            'main_cnae_code' => $this->mainCnaeCode,
            'main_cnae_name' => $this->mainCnaeName,
            'secondary_cnaes' => array_map(
                static fn (CnaeData $item): array => $item->toArray(),
                $this->secondaryCnaes,
            ),
            'address' => $this->address->toArray(),
            'public_email' => $this->publicEmail,
            'public_phone' => $this->publicPhone,
            'public_phone_secondary' => $this->publicPhoneSecondary,
            'public_fax' => $this->publicFax,
            'special_situation' => $this->specialSituation,
            'special_situation_at' => $this->specialSituationAt,
            'simples_optant' => $this->simplesOptant,
            'mei_optant' => $this->meiOptant,
            'state_registrations' => array_map(
                static fn (StateRegistrationData $item): array => $item->toArray(),
                $this->stateRegistrations,
            ),
            'shareholders' => array_map(
                static fn (ShareholderData $item): array => $item->toArray(),
                $this->shareholders,
            ),
            'source_updated_at' => $this->sourceUpdatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $statusRaw = $data['registration_status'] ?? null;
        $status = $statusRaw instanceof RegistrationStatus
            ? $statusRaw
            : RegistrationStatus::fromExternal(is_string($statusRaw) ? $statusRaw : null);

        $secondary = [];
        foreach ($data['secondary_cnaes'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = CnaeData::fromArray($row);
            if ($mapped !== null) {
                $secondary[] = $mapped;
            }
        }

        $ies = [];
        foreach ($data['state_registrations'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = StateRegistrationData::fromArray($row);
            if ($mapped !== null) {
                $ies[] = $mapped;
            }
        }

        $shareholders = [];
        foreach ($data['shareholders'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = ShareholderData::fromArray($row);
            if ($mapped !== null) {
                $shareholders[] = $mapped;
            }
        }

        return new self(
            cnpj: (string) ($data['cnpj'] ?? ''),
            tradeName: self::nullableString($data['trade_name'] ?? null),
            isMatrix: (bool) ($data['is_matrix'] ?? false),
            registrationStatus: $status,
            registrationStatusAt: self::nullableString($data['registration_status_at'] ?? null),
            registrationStatusReason: self::nullableString($data['registration_status_reason'] ?? null),
            activityStartedAt: self::nullableString($data['activity_started_at'] ?? null),
            mainCnaeCode: self::nullableString($data['main_cnae_code'] ?? null),
            mainCnaeName: self::nullableString($data['main_cnae_name'] ?? null),
            address: AddressData::fromArray(is_array($data['address'] ?? null) ? $data['address'] : []),
            publicEmail: self::nullableString($data['public_email'] ?? null),
            publicPhone: self::nullableString($data['public_phone'] ?? null),
            sourceUpdatedAt: self::nullableString($data['source_updated_at'] ?? null),
            secondaryCnaes: $secondary,
            stateRegistrations: $ies,
            shareholders: $shareholders,
            publicPhoneSecondary: self::nullableString($data['public_phone_secondary'] ?? null),
            publicFax: self::nullableString($data['public_fax'] ?? null),
            specialSituation: self::nullableString($data['special_situation'] ?? null),
            specialSituationAt: self::nullableString($data['special_situation_at'] ?? null),
            simplesOptant: self::nullableBool($data['simples_optant'] ?? null),
            meiOptant: self::nullableBool($data['mei_optant'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return null;
    }
}
