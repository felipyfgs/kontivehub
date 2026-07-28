<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;

final readonly class CredentialVersionRegistrationData
{
    public function __construct(
        public SerproEnvironment $environment,
        public string $pfxBinary,
        public string $password,
        public string $consumerKey,
        public string $consumerSecret,
        public ?string $notes,
        public ?int $contractId,
    ) {}
}
