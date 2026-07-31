<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TechnicalConsentGrantData;
use App\Exceptions\TenantSettingsApiException;
use App\Models\TenantTechnicalConsent;
use App\Services\Certificates\TenantTechnicalConsentService;
use RuntimeException;

final readonly class GrantTenantTechnicalConsentAction
{
    public function __construct(
        private TenantTechnicalConsentService $consents,
    ) {}

    public function __invoke(
        TechnicalConsentGrantData $data,
    ): TenantTechnicalConsent {
        try {
            return $this->consents->grant(
                accepted: true,
                actorUserId: $data->actorUserId,
                versionCode: $data->versionCode,
            );
        } catch (RuntimeException $error) {
            throw TenantSettingsApiException::consentGrantFailed($error->getMessage());
        }
    }
}
