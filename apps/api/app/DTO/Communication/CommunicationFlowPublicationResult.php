<?php

namespace App\DTO\Communication;

use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowVersion;

final readonly class CommunicationFlowPublicationResult
{
    public function __construct(
        public CommunicationFlowVersion $version,
        public CommunicationFlow $flow,
        public int $enabledBindings,
    ) {}
}
