<?php

namespace App\DTO\FgtsDigital;

use Carbon\CarbonImmutable;

final readonly class FgtsDigitalRepresentationData
{
    public function __construct(
        public int $clientId,
        public CarbonImmutable $validTo,
        public string $profileType,
    ) {}
}
