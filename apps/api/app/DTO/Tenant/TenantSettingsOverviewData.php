<?php

namespace App\DTO\Tenant;

use App\Models\TenantInstitutionalProfile;

final readonly class TenantSettingsOverviewData
{
    public function __construct(
        public TenantInstitutionalProfile $profile,
        public TenantConsentStatusData $consent,
        public TenantCertificateData $certificate,
    ) {}
}
