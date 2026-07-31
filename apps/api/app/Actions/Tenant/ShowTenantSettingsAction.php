<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SettingsOverviewData;
use App\Services\Certificates\TenantInstitutionalProfileService;

final readonly class ShowTenantSettingsAction
{
    public function __construct(
        private TenantInstitutionalProfileService $profiles,
        private GetTenantConsentStatusAction $getConsentStatus,
        private GetTenantCertificateAction $getCertificate,
    ) {}

    public function __invoke(): SettingsOverviewData
    {
        return new SettingsOverviewData(
            profile: $this->profiles->forCurrentTenant(),
            consent: ($this->getConsentStatus)(),
            certificate: ($this->getCertificate)(),
        );
    }
}
