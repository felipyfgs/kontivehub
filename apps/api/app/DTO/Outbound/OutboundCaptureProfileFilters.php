<?php

namespace App\DTO\Outbound;

final readonly class OutboundCaptureProfileFilters
{
    public function __construct(
        public ?int $establishmentId,
        public ?int $clientId,
    ) {}
}
