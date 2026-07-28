<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateKind;
use Carbon\CarbonImmutable;

final readonly class ExternalGateAcceptanceData
{
    public function __construct(
        public SerproExternalGateKind $kind,
        public string $ticketReference,
        public string $answerSummary,
        public string $responsibleName,
        public CarbonImmutable $referenceDate,
        public SerproEnvironment $environment,
    ) {}
}
