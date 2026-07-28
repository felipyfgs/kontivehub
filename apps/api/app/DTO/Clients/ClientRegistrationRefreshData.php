<?php

namespace App\DTO\Clients;

final readonly class ClientRegistrationRefreshData
{
    /** @param array<string, mixed>|null $lookup */
    public function __construct(
        public ?array $lookup,
    ) {}
}
