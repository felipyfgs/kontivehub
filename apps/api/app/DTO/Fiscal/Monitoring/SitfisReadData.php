<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SitfisReadData
{
    public function __construct(
        public int $clientId,
    ) {}
}
