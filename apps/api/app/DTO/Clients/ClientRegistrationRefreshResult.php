<?php

namespace App\DTO\Clients;

use App\Models\Client;

final readonly class ClientRegistrationRefreshResult
{
    /** @param array<string, mixed> $lookup */
    public function __construct(
        public Client $client,
        public array $lookup,
    ) {}
}
