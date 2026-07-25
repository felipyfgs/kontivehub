<?php

namespace App\Services\Fiscal\Guides\DTO;

final class GuidePaymentLookupRequest
{
    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly string $systemCode,
        public readonly string $serviceCode,
        public readonly ?string $identifierCode,
        public readonly ?string $competencePeriodKey,
        public readonly ?string $debitRef,
        public readonly ?string $correlationId = null,
    ) {}
}
