<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;

final class TermoValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $signedBy = null,
        public readonly ?string $destinationCnpj = null,
        public readonly ?string $authorIdentity = null,
        public readonly ?CarbonImmutable $validFrom = null,
        public readonly ?CarbonImmutable $validTo = null,
        public readonly ?string $sha256 = null,
        public readonly bool $signatureChecked = false,
        public readonly bool $signatureValid = false,
        public readonly array $limits = [],
        /** LOCAL_VALIDATED | SERPRO_ACCEPTED | SIMULATED | REJECTED */
        public readonly ?string $authorizationState = null,
    ) {}
}
