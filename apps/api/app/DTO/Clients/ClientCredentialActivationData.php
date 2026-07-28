<?php

namespace App\DTO\Clients;

final readonly class ClientCredentialActivationData
{
    public function __construct(
        public string $pfxBinary,
        public string $password,
    ) {}
}
