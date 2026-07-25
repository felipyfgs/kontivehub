<?php

namespace App\DTO\Cnpj;

final class AddressData
{
    public function __construct(
        public readonly ?string $postalCode = null,
        public readonly ?string $streetType = null,
        public readonly ?string $street = null,
        public readonly ?string $number = null,
        public readonly ?string $complement = null,
        public readonly ?string $district = null,
        public readonly ?string $city = null,
        public readonly ?string $cityIbgeCode = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
    ) {}

    /**
     * @return array{
     *   postal_code: ?string,
     *   street_type: ?string,
     *   street: ?string,
     *   number: ?string,
     *   complement: ?string,
     *   district: ?string,
     *   city: ?string,
     *   city_ibge_code: ?string,
     *   state: ?string,
     *   country: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'postal_code' => $this->postalCode,
            'street_type' => $this->streetType,
            'street' => $this->street,
            'number' => $this->number,
            'complement' => $this->complement,
            'district' => $this->district,
            'city' => $this->city,
            'city_ibge_code' => $this->cityIbgeCode,
            'state' => $this->state,
            'country' => $this->country,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            postalCode: self::nullableString($data['postal_code'] ?? null),
            streetType: self::nullableString($data['street_type'] ?? null),
            street: self::nullableString($data['street'] ?? null),
            number: self::nullableString($data['number'] ?? null),
            complement: self::nullableString($data['complement'] ?? null),
            district: self::nullableString($data['district'] ?? null),
            city: self::nullableString($data['city'] ?? null),
            cityIbgeCode: self::nullableString($data['city_ibge_code'] ?? null),
            state: self::nullableString($data['state'] ?? null),
            country: self::nullableString($data['country'] ?? null),
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
}
