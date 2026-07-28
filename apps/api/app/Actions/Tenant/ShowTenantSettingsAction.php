<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantSettingsOverviewData;
use App\Services\Certificates\TenantInstitutionalProfileService;

final readonly class ShowTenantSettingsAction
{
    public function __construct(
        private TenantInstitutionalProfileService $profiles,
        private GetTenantConsentStatusAction $getConsentStatus,
        private GetTenantCertificateAction $getCertificate,
    ) {}

    public function __invoke(): TenantSettingsOverviewData
    {
        return new TenantSettingsOverviewData(
            profile: $this->profiles->forCurrentTenant(),
            consent: ($this->getConsentStatus)(),
            certificate: ($this->getCertificate)(),
        );
    }
}
