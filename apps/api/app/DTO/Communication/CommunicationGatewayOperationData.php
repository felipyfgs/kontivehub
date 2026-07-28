<?php

namespace App\DTO\Communication;

final readonly class CommunicationGatewayOperationData
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public array $parameters = [],
    ) {}
}
