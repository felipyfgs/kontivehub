<?php

namespace App\DTO\Tenant;

use App\Models\TenantTechnicalConsent;

final readonly class TenantConsentStatusData
{
    /** @param list<string> $purposesPresented */
    public function __construct(
        public string $versionCode,
        public array $purposesPresented,
        public ?TenantTechnicalConsent $activeConsent,
    ) {}

    public function requiresConsent(): bool
    {
        return $this->activeConsent === null;
    }
}
