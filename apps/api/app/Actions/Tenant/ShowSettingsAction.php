<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\SettingsOverviewData;
use App\Services\Certificates\TenantInstitutionalProfileService;

final readonly class ShowSettingsAction
{
    public function __construct(
        private TenantInstitutionalProfileService $profiles,
        private GetConsentStatusAction $getConsentStatus,
        private GetCertificateAction $getCertificate,
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
