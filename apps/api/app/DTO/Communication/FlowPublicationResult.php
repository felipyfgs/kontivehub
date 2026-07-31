<?php

namespace App\DTO\Communication;

use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowVersion;

final readonly class FlowPublicationResult
{
    public function __construct(
        public CommunicationFlowVersion $version,
        public CommunicationFlow $flow,
        public int $enabledBindings,
    ) {}
}
