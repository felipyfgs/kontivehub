<?php

namespace App\DTO\Outbound;

use App\Models\OutboundCaptureProfile;

final readonly class OutboundKillSwitchResult
{
    public function __construct(
        public ?OutboundCaptureProfile $profile,
        public ?bool $globalActive,
    ) {}
}
