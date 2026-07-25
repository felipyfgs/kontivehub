<?php

namespace App\DTO\Cnpj;

final class ClientRegistrationData
{
    public function __construct(
        public readonly string $rootCnpj,
        public readonly string $legalName,
        public readonly ?string $legalNatureCode = null,
        public readonly ?string $legalNatureName = null,
        public readonly ?string $companySizeCode = null,
        public readonly ?string $companySizeName = null,
        public readonly ?string $capitalSocial = null,
        public readonly ?string $responsibleQualificationCode = null,
        public readonly ?string $responsibleQualificationName = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root_cnpj' => $this->rootCnpj,
            'legal_name' => $this->legalName,
            'legal_nature_code' => $this->legalNatureCode,
            'legal_nature_name' => $this->legalNatureName,
            'company_size_code' => $this->companySizeCode,
            'company_size_name' => $this->companySizeName,
            'capital_social' => $this->capitalSocial,
            'responsible_qualification_code' => $this->responsibleQualificationCode,
            'responsible_qualification_name' => $this->responsibleQualificationName,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rootCnpj: (string) ($data['root_cnpj'] ?? ''),
            legalName: (string) ($data['legal_name'] ?? ''),
            legalNatureCode: self::nullableString($data['legal_nature_code'] ?? null),
            legalNatureName: self::nullableString($data['legal_nature_name'] ?? null),
            companySizeCode: self::nullableString($data['company_size_code'] ?? null),
            companySizeName: self::nullableString($data['company_size_name'] ?? null),
            capitalSocial: self::nullableString($data['capital_social'] ?? null),
            responsibleQualificationCode: self::nullableString($data['responsible_qualification_code'] ?? null),
            responsibleQualificationName: self::nullableString($data['responsible_qualification_name'] ?? null),
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
