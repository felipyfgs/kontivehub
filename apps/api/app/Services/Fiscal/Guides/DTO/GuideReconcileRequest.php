<?php

namespace App\Services\Fiscal\Guides\DTO;

final class GuideReconcileRequest
{
    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly string $systemCode,
        public readonly string $serviceCode,
        public readonly string $operationCode,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId,
        public readonly ?string $remoteProtocol,
        public readonly ?string $competencePeriodKey,
        public readonly ?string $debitRef,
    ) {}
}
