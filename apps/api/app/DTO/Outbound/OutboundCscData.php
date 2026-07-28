<?php

namespace App\DTO\Outbound;

final readonly class OutboundCscData
{
    public function __construct(
        public string $token,
        public string $identifier,
    ) {}
}
