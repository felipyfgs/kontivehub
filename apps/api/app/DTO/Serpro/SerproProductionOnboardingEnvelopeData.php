<?php

namespace App\DTO\Serpro;

use App\Models\SerproProductionOnboarding;

final readonly class SerproProductionOnboardingEnvelopeData
{
    /**
     * @param  array{version: string, text: string, text_sha256: string}  $consent
     */
    public function __construct(
        public bool $enabled,
        public int $tenantId,
        public array $consent,
        public ?SerproProductionOnboarding $onboarding,
    ) {}
}
