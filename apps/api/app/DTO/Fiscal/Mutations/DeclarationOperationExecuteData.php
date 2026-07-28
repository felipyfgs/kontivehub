<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class DeclarationOperationExecuteData
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $clientId,
        public string $idempotencyKey,
        public string $preflightToken,
        public string $confirmationPhrase,
        public bool $confirmed,
        public array $params,
    ) {}
}
