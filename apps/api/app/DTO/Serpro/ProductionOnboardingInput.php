<?php

namespace App\DTO\Serpro;

final readonly class ProductionOnboardingInput
{
    public function __construct(
        public string $consumerKey,
        public string $consumerSecret,
        public string $pfxBinary,
        public string $certificatePassword,
        public string $idempotencyKey,
        public bool $consentGranted,
    ) {}
}
