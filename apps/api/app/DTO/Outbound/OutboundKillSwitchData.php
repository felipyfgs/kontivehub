<?php

namespace App\DTO\Outbound;

final readonly class OutboundKillSwitchData
{
    public function __construct(
        public bool $active,
        public string $reason,
        public ?int $profileId,
    ) {}
}
