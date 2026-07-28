<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class DeclarationOperationPreflightData
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $clientId,
        public string $idempotencyKey,
        public array $params,
    ) {}
}
