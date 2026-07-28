<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class TaxGuidePreflightData
{
    public function __construct(
        public int $clientId,
        public string $operationKey,
        public ?string $competencePeriodKey,
        public ?string $debitRef,
        public ?int $amountCents,
    ) {}
}
