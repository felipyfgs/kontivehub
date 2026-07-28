<?php

namespace App\DTO\Communication;

final readonly class CommunicationGatewayQueryResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
    ) {}
}
