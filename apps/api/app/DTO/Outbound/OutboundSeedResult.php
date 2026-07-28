<?php

namespace App\DTO\Outbound;

use App\Models\OutboundCaptureProfile;
use App\Models\OutboundSeriesCursor;

final readonly class OutboundSeedResult
{
    public function __construct(
        public OutboundCaptureProfile $profile,
        public OutboundSeriesCursor $series,
    ) {}
}
