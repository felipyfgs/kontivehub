<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class ManualConsultExecuteData
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public int $clientId,
        public string $actionId,
        public bool $confirmed,
        public array $params,
    ) {}
}
