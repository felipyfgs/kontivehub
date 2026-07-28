<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class TaxGuideIssueData
{
    /**
     * @param  array<string, mixed>  $confirmationSummary
     * @param  array<string, mixed>  $operationData
     */
    public function __construct(
        public int $clientId,
        public string $operationKey,
        public ?string $competencePeriodKey,
        public ?string $debitRef,
        public ?int $amountCents,
        public ?string $dueAtIso,
        public bool $explicitConfirmation,
        public array $confirmationSummary,
        public ?string $idempotencyKey,
        public ?string $correlationId,
        public bool $forceReissue,
        public array $operationData,
    ) {}
}
