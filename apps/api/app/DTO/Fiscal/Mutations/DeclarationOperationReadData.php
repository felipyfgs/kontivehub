<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class DeclarationOperationReadData
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $clientId,
        public array $params,
        public bool $confirmed,
    ) {}
}
