<?php

namespace App\DTO\Communication;

final readonly class GatewayOperationData
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public array $parameters = [],
    ) {}
}
