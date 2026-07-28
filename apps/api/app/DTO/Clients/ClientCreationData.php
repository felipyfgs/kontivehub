<?php

namespace App\DTO\Clients;

final readonly class ClientCreationData
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public array $attributes,
    ) {}
}
