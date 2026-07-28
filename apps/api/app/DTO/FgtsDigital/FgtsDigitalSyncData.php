<?php

namespace App\DTO\FgtsDigital;

final readonly class FgtsDigitalSyncData
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public int $clientId,
        public array $parameters,
    ) {}
}
