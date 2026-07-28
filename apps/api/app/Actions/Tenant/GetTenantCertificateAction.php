<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantCertificateData;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Services\Certificates\TenantCredentialService;

final readonly class GetTenantCertificateAction
{
    public function __construct(
        private TenantCredentialService $credentials,
    ) {}

    public function __invoke(bool $refreshExpiryAlerts = true): TenantCertificateData
    {
        if ($refreshExpiryAlerts) {
            $this->credentials->refreshExpiryAlerts();
        }

        $credential = $this->credentials->activeForCurrentTenant();

        return $this->fromCredential($credential);
    }

    /**
     * @param  array<string, mixed>|null  $onboarding
     */
    public function fromCredential(
        ?TenantCredential $credential,
        ?array $onboarding = null,
        bool $removed = false,
    ): TenantCertificateData {
        $links = $credential === null
            ? collect()
            : TenantCredentialPurposeLink::query()
                ->where('tenant_credential_id', $credential->id)
                ->where('status', 'ACTIVE')
                ->orderBy('purpose')
                ->get();

        return new TenantCertificateData(
            certificate: $credential,
            purposeLinks: $links,
            alerts: $removed ? [] : $this->credentials->panelExpiryAlerts($credential),
            onboarding: $onboarding,
            removed: $removed,
        );
    }
}
