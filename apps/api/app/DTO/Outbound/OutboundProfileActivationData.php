<?php

namespace App\DTO\Outbound;

final readonly class OutboundProfileActivationData
{
    public function __construct(
        public string $mandateReference,
        public bool $allowlisted,
    ) {}
}
