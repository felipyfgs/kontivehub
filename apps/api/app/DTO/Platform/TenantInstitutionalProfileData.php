<?php

namespace App\DTO\Platform;

final readonly class TenantInstitutionalProfileData
{
    public function __construct(
        public ?string $cnpj,
        public string $legalName,
        public string $institutionalEmail,
        public string $institutionalPhone,
    ) {}

    /**
     * @return array{
     *     cnpj: string|null,
     *     legal_name: string,
     *     institutional_email: string,
     *     institutional_phone: string
     * }
     */
    public function toArray(): array
    {
        return [
            'cnpj' => $this->cnpj,
            'legal_name' => $this->legalName,
            'institutional_email' => $this->institutionalEmail,
            'institutional_phone' => $this->institutionalPhone,
        ];
    }
}
