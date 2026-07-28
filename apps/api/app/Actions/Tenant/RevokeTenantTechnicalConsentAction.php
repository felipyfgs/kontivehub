<?php

namespace App\Actions\Tenant;

use App\Exceptions\TenantSettingsApiException;
use App\Models\TenantTechnicalConsent;
use App\Services\Certificates\TenantTechnicalConsentService;

final readonly class RevokeTenantTechnicalConsentAction
{
    public function __construct(
        private TenantTechnicalConsentService $consents,
    ) {}

    public function __invoke(int $actorUserId): TenantTechnicalConsent
    {
        return $this->consents->revoke($actorUserId)
            ?? throw TenantSettingsApiException::consentNotFound();
    }
}
