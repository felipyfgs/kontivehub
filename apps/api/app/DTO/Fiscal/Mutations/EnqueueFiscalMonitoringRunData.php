<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class EnqueueFiscalMonitoringRunData
{
    public function __construct(
        public int $clientId,
        public string $systemCode,
        public string $serviceCode,
        public string $operationCode,
        public ?string $correlationId,
    ) {}
}
