<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\ConsentStatusData;
use App\Models\TenantTechnicalConsent;
use App\Services\Certificates\TenantTechnicalConsentService;

final readonly class GetTenantConsentStatusAction
{
    public function __construct(
        private TenantTechnicalConsentService $consents,
    ) {}

    public function __invoke(): ConsentStatusData
    {
        $version = TenantTechnicalConsent::VERSION_CERTIFICATE_V1;

        return new ConsentStatusData(
            versionCode: $version,
            purposesPresented: TenantTechnicalConsentService::DEFAULT_PURPOSES,
            activeConsent: $this->consents->activeForCurrentTenant($version),
        );
    }
}
