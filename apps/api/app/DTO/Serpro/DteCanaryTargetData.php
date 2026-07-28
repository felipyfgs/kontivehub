<?php

namespace App\DTO\Serpro;

final readonly class DteCanaryTargetData
{
    public function __construct(
        public int $tenantId,
        public int $clientId,
    ) {}
}
