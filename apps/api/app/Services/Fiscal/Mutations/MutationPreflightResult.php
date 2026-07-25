<?php

namespace App\Services\Fiscal\Mutations;

use App\Models\FiscalMutationOperation;

/**
 * Resposta de preflight (13.2): efeito, contribuinte, competência, elegibilidade, custo, confirmação.
 */
final class MutationPreflightResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $payload,
        public readonly ?FiscalMutationOperation $operation = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
