<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class GenerateMeiDasData
{
    /**
     * @param  list<string>  $competencies
     */
    public function __construct(
        public int $clientId,
        public array $competencies,
        public string $dueDate,
        public string $outputFormat,
        public string $idempotencyKey,
        public ?string $preflightToken,
        public ?string $confirmationPhrase,
    ) {}
}
