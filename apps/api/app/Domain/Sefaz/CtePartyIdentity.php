<?php

namespace App\Domain\Sefaz;

use App\Enums\FiscalRole;

/**
 * Identidade fiscal extraída de um grupo do CT-e.
 */
final readonly class CtePartyIdentity
{
    public function __construct(
        public FiscalRole $role,
        public ?string $cnpj,
        public ?string $name = null,
    ) {}

    public function hasCnpj(): bool
    {
        return $this->cnpj !== null && $this->cnpj !== '';
    }
}
