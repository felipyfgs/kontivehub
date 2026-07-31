<?php

namespace App\DTO\Tenant;

use App\Models\TenantInstitutionalProfile;

final readonly class SettingsOverviewData
{
    public function __construct(
        public TenantInstitutionalProfile $profile,
        public ConsentStatusData $consent,
        public CertificateData $certificate,
    ) {}
}
