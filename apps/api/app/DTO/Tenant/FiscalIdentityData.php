<?php

namespace App\DTO\Tenant;

final readonly class FiscalIdentityData
{
    public function __construct(
        public string $cnpj,
        public ?string $legalName,
    ) {}
}
