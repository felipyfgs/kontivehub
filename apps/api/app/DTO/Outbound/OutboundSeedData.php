<?php

namespace App\DTO\Outbound;

final readonly class OutboundSeedData
{
    public function __construct(
        public string $environment,
        public string $xml,
    ) {}
}
