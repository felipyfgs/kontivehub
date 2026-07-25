<?php

namespace App\Services\MeiAutomation\Providers;

use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\MeiProvider;
use App\Models\MeiAutomationAttempt;

final readonly class MeiProviderOutcome
{
    public function __construct(
        public FiscalAdapterResult $result,
        public MeiProvider $provider,
        public bool $fallbackEligible = false,
        public bool $submitted = false,
        public ?string $fallbackReason = null,
        public ?MeiAutomationAttempt $attempt = null,
    ) {}
}
