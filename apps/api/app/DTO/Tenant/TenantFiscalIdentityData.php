<?php

namespace App\DTO\Tenant;

final readonly class TenantFiscalIdentityData
{
    public function __construct(
        public string $cnpj,
        public ?string $legalName,
    ) {}
}
