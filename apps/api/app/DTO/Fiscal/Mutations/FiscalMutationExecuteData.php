<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class FiscalMutationExecuteData
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $clientId,
        public string $solutionCode,
        public string $serviceCode,
        public string $operationCode,
        public string $operationKey,
        public string $confirmationPhrase,
        public bool $confirmed,
        public ?string $competencePeriodKey,
        public string $idempotencyKey,
        public string $preflightToken,
        public ?string $environment,
        public ?string $module,
        public array $payload,
    ) {}
}
